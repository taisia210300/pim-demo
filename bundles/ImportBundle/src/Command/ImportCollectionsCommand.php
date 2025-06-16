<?php
namespace ImportBundle\Command;

use ImportBundle\Service\ImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCollectionsCommand extends Command
{
    protected static $defaultName = 'app:import:collections';

    private ImportService $importService;

    public function __construct(ImportService $importService)
    {
        parent::__construct();
        $this->importService = $importService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Import collections from XLSX file')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to XLSX file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        return $this->importService->import($filePath, $output);
    }
}
