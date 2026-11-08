<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\AdminLoginInput;
use App\Security\AdminUser;
use App\Security\AdminUserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('admin:bootstrap')]
final class BootstrapAdminCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $repository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly string $bootstrapUsername,
        private readonly string $bootstrapPassword,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $credentials = new AdminLoginInput($this->bootstrapUsername, $this->bootstrapPassword);

        if (0 !== count($this->validator->validate($credentials))) {
            $io->error($this->translator->trans('auth.admin_bootstrap.invalid', locale: 'ru'));

            return Command::INVALID;
        }

        $user = new AdminUser('', $credentials->username, '');
        $hash = $this->passwordHasher->hashPassword($user, $credentials->password);
        $this->repository->provision($credentials->username, $hash);
        $io->success($this->translator->trans('auth.admin_bootstrap.ready', locale: 'ru'));

        return Command::SUCCESS;
    }
}
