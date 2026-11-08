<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\AdminLoginInput;
use App\Security\AdminUser;
use App\Security\AdminUserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('admin:create')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $repository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED);
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, default: 'ru');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = (string) $input->getOption('locale');

        if (!in_array($locale, ['ru', 'en'], true)) {
            return Command::INVALID;
        }

        $username = trim((string) $input->getArgument('username'));
        $password = $io->askHidden($this->translator->trans('auth.admin_create.password', locale: $locale));

        if (null === $password || 0 !== count($this->validator->validate(new AdminLoginInput($username, $password)))) {
            $io->error($this->translator->trans('auth.admin_create.invalid', locale: $locale));

            return Command::INVALID;
        }

        $user = new AdminUser('', $username, '');
        $hash = $this->passwordHasher->hashPassword($user, $password);

        try {
            $this->repository->create($username, $hash);
        } catch (UniqueConstraintViolationException) {
            $io->error($this->translator->trans('auth.admin_create.exists', locale: $locale));

            return Command::FAILURE;
        }

        $io->success($this->translator->trans('auth.admin_create.created', locale: $locale));

        return Command::SUCCESS;
    }
}
