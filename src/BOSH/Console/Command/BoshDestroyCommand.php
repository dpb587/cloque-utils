<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDestroyCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:destroy')
            ->setDescription('Completely destroy a BOSH deployment')
            ->setDefinition(
                [
                    new InputArgument(
                        'director',
                        InputArgument::REQUIRED,
                        'Director name'
                    ),
                    new InputArgument(
                        'deployment',
                        InputArgument::REQUIRED,
                        'Deployment name'
                    ),
                    new InputOption(
                        'component',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Component name',
                        null
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $destManifest = sprintf(
            '%s/compiled/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getArgument('director'),
            $input->getArgument('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $descriptorspec = array(
           0 => STDIN,
           1 => STDOUT,
           2 => STDERR,
        );

        $ph = proc_open(
            sprintf(
                'bosh %s %s -c %s -d %s delete deployment %s',
                $output->isDecorated() ? '--color' : '--no-color',
                $input->isInteractive() ? '' : '--non-interactive',
                escapeshellarg($input->getOption('basedir') . '/' . $input->getArgument('director') . '/.bosh_config'),
                escapeshellarg($destManifest),
                escapeshellarg($input->getArgument('deployment') . ($input->getOption('component') ? ('-' . $input->getOption('component')) : ''))
            ),
            $descriptorspec,
            $pipes,
            getcwd(),
            null
        );

        $status = proc_get_status($ph);

        do {
            pcntl_waitpid($status['pid'], $pidstatus);
        } while (!pcntl_wifexited($pidstatus));

        return pcntl_wexitstatus($pidstatus);
    }
}
