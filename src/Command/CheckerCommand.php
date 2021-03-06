<?php
/**
 * PHP Docblock Checker
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/php-docblock-checker/blob/master/LICENSE.md
 * @link         http://www.phptesting.org/
 */

namespace PhpDocBlockChecker\Command;

use DirectoryIterator;
use PhpDocBlockChecker\Config\Config;
use PhpDocBlockChecker\Config\ConfigParser;
use PhpDocBlockChecker\Config\ConfigProcessor;
use PhpDocBlockChecker\FileProcessor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to check a directory of PHP files for Docblocks.
 * @author Dan Cryer <dan@block8.co.uk>
 */
class CheckerCommand extends Command {
   /**
    * @var string
    */
   protected $basePath = './';

   /**
    * @var array
    */
   protected $errors = [];

   /**
    * @var array
    */
   protected $warnings = [];

   /**
    * @var array
    */
   protected $infos = [];

   /**
    * @var array
    */
   protected $exclude = [];

   /**
    * @var array
    */
   protected $cache;

   /**
    * @var OutputInterface
    */
   protected $output;

   /**
    * @var int
    */
   protected $passed = 0;

   /**
    * @var Parser
    */
   protected $parser;

   /**
    * @var Config
    */
   private $config;

   /**
    * Configure the console command, add options, etc.
    */
   protected function configure() {
      $this
         ->setName('check')
         ->setDescription('Check PHP files within a directory for appropriate use of Docblocks.')
         ->addOption('exclude', 'x', InputOption::VALUE_REQUIRED, 'Files and directories to exclude.', null)
         ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Directory to scan.', './')
         ->addOption('skip-classes', null, InputOption::VALUE_NONE, 'Don\'t check classes for docblocks.')
         ->addOption('skip-methods', null, InputOption::VALUE_NONE, 'Don\'t check methods for docblocks.')
         ->addOption('skip-signatures', null, InputOption::VALUE_NONE, 'Don\'t check docblocks against method signatures.')
         ->addOption('only-signatures', null, InputOption::VALUE_NONE, 'Ignore missing docblocks where method doesn\'t have parameters or return type.')
         ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output JSON instead of a log.')
         ->addOption('files-per-line', 'l', InputOption::VALUE_REQUIRED, 'Number of files per line in progress', 50)
         ->addOption('fail-on-warnings', 'w', InputOption::VALUE_NONE, 'Consider the check failed if any warnings are produced.')
         ->addOption('info-only', 'i', InputOption::VALUE_NONE, 'Information-only mode, just show summary.')
         ->addOption('from-stdin', null, InputOption::VALUE_NONE, 'Use list of files from stdin (e.g. git diff)')
         ->addOption('cache-file', null, InputOption::VALUE_REQUIRED, 'Cache analysis of files based on filemtime.')
         ->addOption('config-file', null, InputOption::VALUE_REQUIRED, 'File to read doccheck config from in yml format');
   }

   /**
    * Execute the actual docblock checker.
    * @param InputInterface $input
    * @param OutputInterface $output
    * @return int
    */
   protected function execute(InputInterface $input, OutputInterface $output) {
      $startTime = microtime(true);

      $this->output = $output;
      $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
      $this->config = (new ConfigProcessor(new ConfigParser($input, $this->getDefinition())))->processConfig();

      // Check base path ends with a slash:
      if (substr($this->basePath, -1) !== '/') {
         $this->basePath .= '/';
      }

      $cacheFile = $this->config->getCacheFile();
      // Load cache from file if set:
      if (!empty($cacheFile) && file_exists($cacheFile)) {
         $this->cache = json_decode(file_get_contents($cacheFile), true);
      }

      // Get files to check:
      $files = [];

      if ($this->config->isFromStdin()) {
         $this->processStdin($files);
      } else {
         $this->processDirectory('', $files);
      }

      // Check files:
      $filesPerLine = $this->config->getFilesPerLine();
      $totalFiles = count($files);
      $files = array_chunk($files, $filesPerLine);
      $processed = 0;
      $fileCountLength = strlen((string)$totalFiles);

      if ($this->config->isVerbose()) {
         $output->writeln('');
         $output->writeln('PHP Docblock Checker <fg=blue>by Dan Cryer (https://www.dancryer.com)</>');
         $output->writeln('');
      }

      while (count($files)) {
         $chunk = array_shift($files);
         $chunkFiles = count($chunk);

         while (count($chunk)) {
            $processed++;
            $file = array_shift($chunk);

            list($errors, $warnings) = $this->processFile($file);

            if ($this->config->isVerbose()) {
               if ($errors) {
                  $this->output->write('<fg=red>F</>');
               } elseif ($warnings) {
                  $this->output->write('<fg=yellow>W</>');
               } else {
                  $this->output->write('<info>.</info>');
               }
            }
         }

         if ($this->config->isVerbose()) {
            $this->output->write(str_pad('', $filesPerLine - $chunkFiles));
            $this->output->writeln('  ' . str_pad($processed, $fileCountLength, ' ', STR_PAD_LEFT) . '/' . $totalFiles . ' (' . floor((100 / $totalFiles) * $processed) . '%)');
         }
      }

      if ($this->config->isVerbose()) {
         $time = round(microtime(true) - $startTime, 2);
         $this->output->writeln('');
         $this->output->writeln('');
         $this->output->writeln('Checked ' . number_format($totalFiles) . ' files in ' . $time . ' seconds.');
         $this->output->write('<info>' . number_format($this->passed) . ' Passed</info>');
         $this->output->write(' / <fg=red>' . number_format(count($this->errors)) . ' Errors</>');
         $this->output->write(' / <fg=yellow>' . number_format(count($this->warnings)) . ' Warnings</>');
         $this->output->write(' / <fg=blue>' . number_format(count($this->infos)) . ' Info</>');

         $this->output->writeln('');

         if (count($this->errors) && !$this->config->isInfoOnly()) {
            $this->output->writeln('');
            $this->output->writeln('');

            foreach ($this->errors as $error) {
               $this->output->write('<fg=red>ERROR   </> ' . $error['file'] . ':' . $error['line'] . ' - ');

               if ($error['type'] === 'class') {
                  $this->output->write('Class <info>' . $error['class'] . '</info> is missing a docblock.');
               }

               if ($error['type'] === 'method') {
                  $this->output->write('Method <info>' . $error['method'] . '</info> is missing a docblock.');
               }

               $this->output->writeln('');
            }
         }

         if (count($this->infos) && !$this->config->isInfoOnly()) {
            $this->output->writeln('');
            $this->output->writeln('');

            foreach ($this->infos as $info) {
               $this->output->write('<fg=blue>INFO   </> ' . $info['file'] . ':' . $info['line'] . ' - ');

               if ($info['type'] === 'class') {
                  $this->output->write('Class <info>' . $info['class'] . '</info> is missing a docblock.');
               }

               if ($info['type'] === 'method') {
                  $this->output->write('Method <info>' . $info['method'] . '</info> is missing a docblock.');
               }

               $this->output->writeln('');
            }
         }

         if (count($this->warnings) && !$this->config->isInfoOnly()) {
            foreach ($this->warnings as $error) {
               $this->output->write('<fg=yellow>WARNING </> ');

               if ($error['type'] === 'param-missing') {
                  $this->output->write('<info>' . $error['method'] . '</info> - @param <fg=blue>' . $error['param'] . '</> missing.');
               }

               if ($error['type'] === 'param-mismatch') {
                  $this->output->write('<info>' . $error['method'] . '</info> - @param <fg=blue>' . $error['param'] . '</> (' . $error['doc-type'] . ')  does not match method signature (' . $error['param-type'] . ').');
               }

               if ($error['type'] === 'return-missing') {
                  $this->output->write('<info>' . $error['method'] . '</info> - @return missing.');
               }

               if ($error['type'] === 'return-mismatch') {
                  $this->output->write('<info>' . $error['method'] . '</info> - @return <fg=blue>' . $error['doc-type'] . '</>  does not match method signature (' . $error['return-type'] . ').');
               }

               $this->output->writeln('');
            }
         }

         $this->output->writeln('');
      }

      // Output JSON if requested:
      if ($this->config->isJson()) {
         print json_encode(array_merge($this->errors, $this->warnings));
      }

      // Write to cache file:
      if (!empty($cacheFile)) {
         @file_put_contents($cacheFile, json_encode($this->cache));
      }

      return count($this->errors) || ($this->config->isFailOnWarnings() && count($this->warnings)) ? 1 : 0;
   }

   /**
    * Iterate through a directory and check all of the PHP files within it.
    * @param string $path
    * @param string[] $worklist
    */
   protected function processDirectory($path = '', array &$worklist = []) {
      $dir = new DirectoryIterator($this->basePath . $path);

      foreach ($dir as $item) {
         if ($item->isDot()) {
            continue;
         }

         $itemPath = $path . $item->getFilename();

         if ($this->isFileExcluded($itemPath)) {
            continue;
         }

         if ($item->isFile() && $item->getExtension() === 'php') {
            $worklist[] = $itemPath;
         }

         if ($item->isDir()) {
            $this->processDirectory($itemPath . '/', $worklist);
         }
      }
   }

   /**
    * Iterate through a list of files provided via stdin and add all PHP files to the worklist.
    * @param string[] $worklist
    * @return array
    */
   protected function processStdin(array &$worklist = []) {
      $files = file('php://stdin');

      if (empty($files) || !is_array($files) || !count($files)) {
         return [];
      }

      foreach ($files as $file) {
         $file = trim($file);

         if (!is_file($file)) {
            continue;
         }

         if ($this->isFileExcluded($file)) {
            continue;
         }

         if (substr($file, -3) === 'php') {
            $worklist[] = $file;
         }
      }
   }

   /**
    * @param string $file
    * @return bool
    */
   private function isFileExcluded($file) {
      if (in_array($file, $this->exclude)) {
         return true;
      }

      foreach ($this->exclude as $pattern) {
         if (fnmatch($pattern, $file)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Check a specific PHP file for errors.
    * @param string $fileName
    * @return array
    */
   protected function processFile($fileName) {
      $errors = false;
      $warnings = false;
      $fullPath = $this->basePath . $fileName;

      if (empty($this->cache[$fullPath]) || filemtime($fullPath) > $this->cache[$fullPath]['mtime']) {
         $processor = new FileProcessor($fullPath, $this->parser);

         $this->cache[$fullPath] = [
            'mtime' => filemtime($fullPath),
            'classes' => $processor->getClasses(),
            'methods' => $processor->getMethods(),
         ];

         unset($processor);
      }

      $file = $this->cache[$fullPath];

      if (!$this->config->isSkipClasses()) {
         foreach ($file['classes'] as $name => $class) {
            if ($class['docblock'] === null) {
               $errors = true;
               $this->errors[] = [
                  'type' => 'class',
                  'file' => $fileName,
                  'class' => $name,
                  'line' => $class['line'],
               ];
            }
         }
      }

      if (!$this->config->isSkipMethods()) {
         foreach ($file['methods'] as $name => $method) {
            $treatAsError = true;

            if ($this->config->isOnlySignatures()) {
               if ((empty($method['params']) || 0 === count($method['params'])) && false === $method['has_return']) {
                  $treatAsError = false;
               }
            }

            if ($method['docblock'] === null) {
               if (true === $treatAsError) {
                  $errors = true;

                  $this->errors[] = [
                     'type' => 'method',
                     'file' => $fileName,
                     'class' => $name,
                     'method' => $name,
                     'line' => $method['line'],
                  ];
               } else {
                  $this->infos[] = [
                     'type' => 'method',
                     'file' => $fileName,
                     'class' => $name,
                     'method' => $name,
                     'line' => $method['line'],
                  ];
               }
            }
         }
      }

      if (!$this->config->isSkipSignatures()) {
         foreach ($file['methods'] as $name => $method) {
            // If the docblock is inherited, we can't check for params and return types:
            if (isset($method['docblock']['inherit']) && $method['docblock']['inherit']) {
               continue;
            }

            if (count($method['params'])) {
               foreach ($method['params'] as $param => $type) {
                  if (!isset($method['docblock']['params'][$param])) {
                     $warnings = true;
                     $this->warnings[] = [
                        'type' => 'param-missing',
                        'file' => $fileName,
                        'class' => $name,
                        'method' => $name,
                        'line' => $method['line'],
                        'param' => $param,
                     ];
                  } elseif (!empty($type)) {
                     $docBlockTypes = explode('|', $method['docblock']['params'][$param]);
                     $methodTypes = explode('|', $type);

                     sort($docBlockTypes);
                     sort($methodTypes);

                     if ($docBlockTypes !== $methodTypes) {
                        if ($type === 'array' && substr($method['docblock']['params'][$param], -2) === '[]') {
                           // Do nothing because this is fine.
                        } else {
                           $warnings = true;
                           $this->warnings[] = [
                              'type' => 'param-mismatch',
                              'file' => $fileName,
                              'class' => $name,
                              'method' => $name,
                              'line' => $method['line'],
                              'param' => $param,
                              'param-type' => $type,
                              'doc-type' => $method['docblock']['params'][$param],
                           ];
                        }
                     }
                  }
               }
            }

            if (!empty($method['return'])) {
               if (empty($method['docblock']['return'])) {
                  $warnings = true;
                  $this->warnings[] = [
                     'type' => 'return-missing',
                     'file' => $fileName,
                     'class' => $name,
                     'method' => $name,
                     'line' => $method['line'],
                  ];
               } elseif (is_array($method['return'])) {
                  $docblockTypes = explode('|', $method['docblock']['return']);
                  sort($docblockTypes);
                  if ($method['return'] !== $docblockTypes) {
                     $warnings = true;
                     $this->warnings[] = [
                        'type' => 'return-mismatch',
                        'file' => $fileName,
                        'class' => $name,
                        'method' => $name,
                        'line' => $method['line'],
                        'return-type' => implode('|', $method['return']),
                        'doc-type' => $method['docblock']['return'],
                     ];
                  }
               } elseif ($method['docblock']['return'] !== $method['return']) {
                  if ($method['return'] === 'array' && substr($method['docblock']['return'], -2) === '[]') {
                     // Do nothing because this is fine.
                  } else {
                     $warnings = true;
                     $this->warnings[] = [
                        'type' => 'return-mismatch',
                        'file' => $fileName,
                        'class' => $name,
                        'method' => $name,
                        'line' => $method['line'],
                        'return-type' => $method['return'],
                        'doc-type' => $method['docblock']['return'],
                     ];
                  }
               }
            }
         }
      }

      if (!$errors) {
         $this->passed++;
      }

      return [$errors, $warnings];
   }
}
