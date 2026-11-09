<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Doctrine;

use App\Surveys\Domain\Entity\Survey;
use App\Surveys\Domain\Entity\SurveyOption;
use App\Surveys\Domain\Entity\SurveyQuestion;
use App\Surveys\Domain\Repository\SurveyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSurveyRepository implements SurveyRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?Survey
    {
        return $this->entityManager->find(Survey::class, $id);
    }

    public function findQuestion(string $id): ?SurveyQuestion
    {
        return $this->entityManager->find(SurveyQuestion::class, $id);
    }

    public function findOption(string $id): ?SurveyOption
    {
        return $this->entityManager->find(SurveyOption::class, $id);
    }

    public function questionsOf(string $surveyId): array
    {
        return $this->entityManager->getRepository(SurveyQuestion::class)->findBy(['surveyId' => $surveyId], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function optionsOf(array $questionIds): array
    {
        if ([] === $questionIds) {
            return [];
        }

        return $this->entityManager->getRepository(SurveyOption::class)->findBy(['questionId' => $questionIds], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function save(Survey|SurveyQuestion|SurveyOption ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function delete(array $removed, Survey|SurveyQuestion|SurveyOption ...$kept): void
    {
        foreach ($removed as $entity) {
            $this->entityManager->remove($entity);
        }
        foreach ($kept as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }
}
