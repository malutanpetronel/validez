<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Primul administrator (si alti utilizatori) pana exista inregistrare publica.
 * Parola se cere interactiv, ascuns, de doua ori - nu ca argument (ar ramane in istoricul shell-ului).
 */
#[AsCommand(name: 'app:user:create', description: 'Creează un utilizator; --admin îi dă ROLE_ADMIN')]
final class CreateUserCommand extends Command
{
    private const MIN_PASSWORD = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Emailul (identificatorul de login)')
            ->addArgument('displayName', InputArgument::REQUIRED, 'Numele afișat')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Acordă ROLE_ADMIN (poate modifica structura arborelui)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = new User(
            (string) $input->getArgument('email'),
            (string) $input->getArgument('displayName'),
            $input->getOption('admin') ? [User::ROLE_ADMIN] : [],
        );

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $v) {
                $io->error($v->getPropertyPath().': '.$v->getMessage());
            }

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Parola (minim '.self::MIN_PASSWORD.' caractere)');
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $io->error('Parola e prea scurtă.');

            return Command::FAILURE;
        }
        if ($password !== (string) $io->askHidden('Repetă parola')) {
            $io->error('Parolele nu coincid.');

            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('Utilizator creat: %s (%s)%s', $user->getEmail(), $user->getId()->toBase32(), $user->isAdmin() ? ' — administrator' : ''));

        return Command::SUCCESS;
    }
}
