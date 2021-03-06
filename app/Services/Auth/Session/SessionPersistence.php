<?php
declare(strict_types=1);

namespace App\Services\Auth\Session;

use App\Entity\Persistence;
use App\Entity\User;
use App\Repository\Persistence\PersistenceRepository;
use App\Repository\User\UserRepository;
use App\Services\Auth\Checkpoint\Pool;
use App\Services\Auth\Generators\CodeGenerator;
use App\Services\Auth\Session\Driver\Driver;

/**
 * Class SessionPersistence
 * Creates a user session and controls persistence.
 * Persistence in this context means the stored state of user authentication.
 * Work with persistence is done with the help of the {@see Driver}.
 *
 * @see Persistence
 */
class SessionPersistence
{
    /**
     * @var UserRepository
     */
    private $userRepository;

    /**
     * @var PersistenceRepository
     */
    private $persistenceRepository;

    /**
     * @var CodeGenerator
     */
    private $codeGenerator;

    /**
     * @var Driver
     */
    private $sessionDriver;

    /**
     * @var Pool
     */
    private $checkpointsPool;

    public function __construct(
        UserRepository $userRepository,
        PersistenceRepository $persistenceRepository,
        CodeGenerator $keyGenerator,
        Driver $sessionDriver,
        Pool $checkpointsPool)
    {
        $this->userRepository = $userRepository;
        $this->persistenceRepository = $persistenceRepository;
        $this->codeGenerator = $keyGenerator;
        $this->sessionDriver = $sessionDriver;
        $this->checkpointsPool = $checkpointsPool;
    }

    /**
     * Creates a session and persistence for the transferred user.
     *
     * @param User $user
     * @param bool $remember
     *
     * @return Session
     */
    public function createFromUser(User $user, bool $remember): Session
    {
        if (!$remember) {
            return new Session($user);
        }

        do {
            $code = $this->codeGenerator->generate(Persistence::CODE_LENGTH);
        } while($this->persistenceRepository->findByCode($code));

        $persistence = new Persistence($code, $user);
        $this->persistenceRepository->create($persistence);
        $this->sessionDriver->set($persistence->getCode());

        return new Session($user);
    }

    /**
     * Attempts to create a session from persistence storage. Otherwise it returns an empty session.
     *
     * @return Session
     */
    public function createFromPersistenceStorage(): Session
    {
        $code = $this->sessionDriver->get();
        if (empty($code)) {
            return $this->createEmpty();
        }

        $persistence = $this->persistenceRepository->findByCode($code);
        if ($persistence === null) {
            return $this->createEmpty();
        }

        // If code lifetime has expired.
        if ($persistence->isExpired()) {
            return $this->createEmpty();
        }
        $user = $this->userRepository->find($persistence->getUser()->getId());
        if ($user === null) {
            return $this->createEmpty();
        }

        if (!$this->checkpointsPool->passCheck($user)) {
            return $this->createEmpty();
        }

        return new Session($user);
    }

    /**
     * Destroys user session and removes persistence.
     *
     * @param User $user
     * @param bool $destroyAll
     */
    public function destroy(User $user, bool $destroyAll): void
    {
        if ($destroyAll) {
            $this->persistenceRepository->deleteByUser($user);
        } else {
            $code = $this->sessionDriver->get();
            if (!empty($code)) {
                $this->persistenceRepository->deleteByCode($code);
            }
        }

        $this->sessionDriver->forget();
    }

    /**
     * Creates an empty session if the user is not authenticated.
     *
     * @return Session
     */
    public function createEmpty(): Session
    {
        return new Session(null);
    }
}
