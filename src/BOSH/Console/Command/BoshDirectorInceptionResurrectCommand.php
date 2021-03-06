<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

class BoshDirectorInceptionResurrectCommand extends AbstractDirectorCommand
{
    protected $privateAws;
    protected $commandInput;
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:inception:resurrect')
            ->setDescription('Resurrect a broken microBOSH')
            ->addArgument(
                'stemcell',
                InputArgument::REQUIRED,
                'BOSH AMI or Stemcell URL'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('><comment>Resurrecting microBOSH</comment>...');
        $h = new \BOSH\Console\Command\BoshDirectorHelpers($input, $output);
        $awsHelper = new \BOSH\Console\Command\AWSHelpers($h->ec2Client, $output);

        $previousDeploymentFile = $input->getOption('basedir') . '/compiled/' . $input->getOption('director') . '/bosh-deployments.yml';
        if (!file_exists($previousDeploymentFile)) 
        {
            $output->writeln("  > <info>unable for find previous deployments in: $previousDeploymentFile. Aborting since there is nothing to resurrect.</info>");
            return;
        }

        $previousDeploymentInstanceId = $h->getBOSHDeploymentValue('vm_cid');

        $previousDeployment = $h->ec2Client->describeInstances([
            'InstanceIds' => [
                $previousDeploymentInstanceId,
            ],
        ]);

        if  (!empty($previousDeployment['Reservations'])
             &&  $previousDeployment['Reservations'][0]['Instances'][0]['State']['Name'] == 'running') 
        {
            $output->writeln("  > <info>Found existing microBOSH running as instance: $previousDeploymentInstanceId.  Aborting since no resurrection required.</info>");
            return;
        }

        $previousDeploymentDiskId = $h->getBOSHDeploymentValue('disk_cid');
        $output->writeln("> <comment>Existing microBOSH instance $previousDeploymentInstanceId missing.  Launching new microBOSH and then attaching old microBOSH's disk ($previousDeploymentDiskId) to it</comment>...");
        $previousDeploymentFileBackup = $previousDeploymentFile.date("YmdHis");
        $output->writeln("> <comment>Backing up previous deployment settings to $previousDeploymentFileBackup</comment>...");
        copy($previousDeploymentFile, $previousDeploymentFileBackup);

        $resurrectionDeploymentContents = <<<EOT
---
instances:
- :id: 1
  :name: {$h->getBOSHDeploymentValue('name')}
  :uuid: {$h->getBOSHDeploymentValue('uuid')}
disks: []
registry_instances: []
EOT;
        file_put_contents($previousDeploymentFile.'.resurrect',$resurrectionDeploymentContents);

        $output->writeln('  > <info>uploading "empty" bosh-deployments.yml to prevent attempting to delete old microBOSH</info>...');
        $inceptionIp = $h->getInceptionInstanceDetails()['PrivateIpAddress'];
        $h->rsyncToServer(
            $previousDeploymentFile.'.resurrect',
            "ubuntu@$inceptionIp:~/cloque/self/bosh-deployments.yml"
        );

        $output->writeln('  > <info>deploying microBOSH</info>...');
        $this->execCommand($input, $output, 'boshdirector:inception:provision', [ 'stemcell' => $input->getArgument('stemcell') ]);

        $output->writeln('> <comment>Updating ~/.ssh/known_hosts with new microBOSH info</comment>...');
        $boshIP = $h->captureFromServer('ubuntu', $inceptionIp, ['grep -Po \'"ip":"\K(.*?)(?=")\' ~/cloque/self/bosh-deployments.yml'])[0];
        $h->updateTrustedSSHKey($boshIP);

        $output->writeln('> <comment>attaching old microBOSH persistent disk to new microBOSH</comment>...');
        $output->write('  > <info>unmounting new disk</info>...');
        preg_match('/\["(.*)"\]/', $h->captureFromServer('ubuntu', $inceptionIp, ['cd ~/cloque/self','bosh micro agent list_disk'])[0], $newDiskId);
        $newDiskId = $newDiskId[1];
        $h->runOnServer('ubuntu', $inceptionIp, [
            'cd ~/cloque/self',
            'bosh micro agent stop',
            "bosh micro agent unmount_disk $newDiskId",
        ]);
        $output->writeln("new disk: $newDiskId unmounted.");

        $boshInstanceId = $h->captureFromServer('ubuntu', $inceptionIp, ['awk \'/\s+:vm_cid:\s+(.*)/ { print $2 }\' ~/cloque/self/bosh-deployments.yml'])[0];
        $output->writeln("  > <info>Detach new disk $newDiskId and attach old disk $previousDeploymentDiskId to instance: $boshInstanceId</info>");

        $output->write("  > ");
        $awsHelper->detachVolume($newDiskId);
        $awsHelper->attachVolume($boshInstanceId, $previousDeploymentDiskId, '/dev/sdf');
        $awsHelper->deleteVolume($newDiskId);

        $output->writeln("done.");

        $output->writeln('  > <info>Changing disk id reference in (microBOSH):/var/vcap/bosh/settings.json</info>');
        $h->runOnServer('vcap', $boshIP, ["echo c1oudc0w | sudo -p \"\" -S -- sed -i 's/$newDiskId/$previousDeploymentDiskId/' /var/vcap/bosh/settings.json"]);
        $output->writeln('  > <info>Changing disk id reference in  ~/cloque/self/bosh-deployments.yml</info>');
        $h->runOnServer('ubuntu', $inceptionIp, ["sed -i 's/$newDiskId/$previousDeploymentDiskId/' ~/cloque/self/bosh-deployments.yml"]);

        $output->writeln('  > <info>Restarting microBOSH</info>');
        $h->runOnServer('ubuntu', $inceptionIp, [
            'cd ~/cloque/self',
            "bosh micro agent mount_disk $previousDeploymentDiskId",
            'bosh micro agent start',
        ]);

        $h->fetchBoshDeployments($inceptionIp);

        $h->tagDirectorResources();

        $output->writeln('><info>Old microBOSH resurrected successfully</info>');
    }

}
