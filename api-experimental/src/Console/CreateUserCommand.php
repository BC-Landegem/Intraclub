<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\User\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'app:user:create', description: 'Create an API user (for login).')]
final class CreateUserCommand extends Command
{
    public function __construct(private UserRepository $userRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'The username');
        $this->addArgument('password', InputArgument::OPTIONAL, 'The password (prompted securely if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = trim((string) $input->getArgument('username'));
        if ($username === '') {
            $output->writeln('<error>Username must not be empty.</error>');

            return Command::INVALID;
        }

        if ($this->userRepository->existsByUsername($username)) {
            $output->writeln(sprintf('<error>User "%s" already exists.</error>', $username));

            return Command::FAILURE;
        }

        $password = (string) ($input->getArgument('password') ?? '');
        if ($password === '') {
            $question = new Question('Enter a password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) (new QuestionHelper())->ask($input, $output, $question);
        }

        if (strlen($password) < 8) {
            $output->writeln('<error>Password must be at least 8 characters.</error>');

            return Command::INVALID;
        }

        $id = $this->userRepository->createUser($username, (string) password_hash($password, PASSWORD_DEFAULT));
        $output->writeln(sprintf('<info>Created user "%s" (id %d).</info>', $username, $id));

        return Command::SUCCESS;
    }
}
