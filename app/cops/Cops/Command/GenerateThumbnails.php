<?php
/*
 * This file is part of Silex Cops. Licensed under WTFPL
 *
 * (c) Mathieu Duplouy <mathieu.duplouy@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Cops\Command;

use Symfony\Component\Console\Command\Command;
use Silex\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Cops\Model\Core;

/**
 * Thumbnail generation command
 *
 * @author Mathieu Duplouy <mathieu.duplouy@gmail.com>
 */
class GenerateThumbnails extends Command
{
    /**
     * Option value for all databases
     */
    const OPTION_ALL_DB = 'all';

    /**
     * Application instance
     * @var \Silex\Application
     */
    private $app;

    /**
     * Constructor
     *
     * @param string      $name
     * @param Application $app
     */
    public function __construct($name, Application $app)
    {
        parent::__construct('generate:thumbnails');
        $this->app = $app;
        $this->setDescription('Generate the thumbnails for every book');
    }

    /**
     * Configure command
     *
     * @return void
     */
    protected function configure()
    {
        $this->addOption(
            'database',
            null,
            InputOption::VALUE_OPTIONAL,
            'Selected database',
            self::OPTION_ALL_DB
        );

    }

    /**
     * Execute command
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     *
     * @throws InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selectedDb = $input->getOption('database');

        $allDbs = $this->app['config']->getValue('data_dir');
        unset($allDbs[Core::INTERNAL_DB_KEY]);

        if ($selectedDb == self::OPTION_ALL_DB) {
            foreach ($allDbs as $db => $path) {
                $this->generateThumbnails($output, $db);
            }
        } else {

            if (!array_key_exists($selectedDb, $allDbs)) {
                throw new \InvalidArgumentException(
                    sprintf('Database %s does not exists', $selectedDb)
                );
            }

            $output->writeln('');
            $this->generateThumbnails($output, $selectedDb);
        }

        return 1;
    }

    /**
     * Generate thumbnail on provided database
     *
     * @param OutputInterface $output
     *
     * @return void
     */
    private function generateThumbnails(OutputInterface $output, $dbName)
    {
        $this->app['config']->setDatabaseKey($this->app, $dbName);

        $output->writeLn('');
        $output->writeln(sprintf('<fg=green>Generating all book thumbnails for "%s"</fg=green>', $dbName));

        $allBooks = $this->app['model.book']->getCollection()->getAll();

        // Progress bar
        $progress = $this->getHelperSet()->get('progress');
        $progress->start($output, $allBooks->count());

        // Generate each book thumbnail
        foreach($allBooks as $book) {

            $cover = $book->getCover()->setBook($book);

            $cover->getThumbnailPath(160, 260);
            $cover->getThumbnailPath(80, 120);

            $progress->advance();
        }

        $progress->finish();

        $output->writeln('');
        $output->writeln('<fg=green>Done !</fg=green>');
        $output->writeln('');
    }
}
