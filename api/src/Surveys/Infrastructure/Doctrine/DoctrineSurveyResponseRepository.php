<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Doctrine;

use App\Surveys\Domain\Entity\SurveyAnswer;
use App\Surveys\Domain\Entity\SurveyParticipation;
use App\Surveys\Domain\Entity\SurveyResponse;
use App\Surveys\Domain\Repository\SurveyResponseRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSurveyResponseRepository implements SurveyResponseRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findParticipation(string $surveyId, string $userId): ?SurveyParticipation
    {
        return $this->entityManager->getRepository(SurveyParticipation::class)->findOneBy(['surveyId' => $surveyId, 'userId' => $userId]);
    }

    public function findNominativeResponse(string $surveyId, string $userId): ?SurveyResponse
    {
        return $this->entityManager->getRepository(SurveyResponse::class)->findOneBy(['surveyId' => $surveyId, 'userId' => $userId]);
    }

    public function answersOf(string $responseId): array
    {
        return $this->entityManager->getRepository(SurveyAnswer::class)->findBy(['responseId' => $responseId]);
    }

    public function record(SurveyParticipation $participation, SurveyResponse $response, array $answers, array $replaced): void
    {
        foreach ($replaced as $answer) {
            $this->entityManager->remove($answer);
        }
        $this->entityManager->persist($participation);
        $this->entityManager->persist($response);
        foreach ($answers as $answer) {
            $this->entityManager->persist($answer);
        }
        $this->entityManager->flush();
    }
}
