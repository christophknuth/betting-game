<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Router;

use FastRoute\RouteCollector;
use FastRoute\Dispatcher;
use function FastRoute\simpleDispatcher;

final class Router
{
    private Dispatcher $dispatcher;

    public function __construct()
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $r) {
            // Participant - Predictions
            $r->addRoute('GET', '/participants/{participantId:\d+}/predictions', [
                'controller' => 'PredictionController',
                'method' => 'getPredictions'
            ]);
            
            $r->addRoute('GET', '/participants/{participantId:\d+}/predictions/{predictionId}', [
                'controller' => 'PredictionController',
                'method' => 'getPrediction'
            ]);
            
            $r->addRoute('POST', '/participants/{participantId:\d+}/events/{eventId:\d+}/predictions', [
                'controller' => 'PredictionController',
                'method' => 'submitPrediction'
            ]);
            
            $r->addRoute('PUT', '/participants/{participantId:\d+}/predictions/{predictionId}', [
                'controller' => 'PredictionController',
                'method' => 'updatePrediction'
            ]);

            // Participant - Scores
            $r->addRoute('GET', '/participants/{participantId:\d+}/scores', [
                'controller' => 'ScoreController',
                'method' => 'getScores'
            ]);

            $r->addRoute('GET', '/participants/{participantId:\d+}/games/{bettingGameId:\d+}/leaderboard', [
                'controller' => 'ScoreController',
                'method' => 'getLeaderboard'
            ]);

            // Participant - Participations
            $r->addRoute('GET', '/participants/{participantId:\d+}/participations', [
                'controller' => 'ParticipationController',
                'method' => 'getParticipations'
            ]);
            
            $r->addRoute('POST', '/participants/{participantId:\d+}/games/{bettingGameId:\d+}/participation', [
                'controller' => 'ParticipationController',
                'method' => 'joinGame'
            ]);
            
            $r->addRoute('DELETE', '/participants/{participantId:\d+}/games/{bettingGameId:\d+}/participation', [
                'controller' => 'ParticipationController',
                'method' => 'leaveGame'
            ]);

            // Admin - Predictions
            $r->addRoute('GET', '/admin/predictions', [
                'controller' => 'AdminPredictionController',
                'method' => 'getAllPredictions',
                'role' => 'admin'
            ]);

            // Admin - Games
            $r->addRoute('GET', '/admin/games', [
                'controller' => 'AdminGameController',
                'method' => 'getAllGames',
                'role' => 'admin'
            ]);
            
            $r->addRoute('POST', '/admin/games', [
                'controller' => 'AdminGameController',
                'method' => 'createGame',
                'role' => 'admin'
            ]);
            
            $r->addRoute('POST', '/admin/games/{bettingGameId:\d+}/end', [
                'controller' => 'AdminGameController',
                'method' => 'endGame',
                'role' => 'admin'
            ]);

            // Admin - Results
            $r->addRoute('POST', '/admin/events/{eventId:\d+}/results', [
                'controller' => 'AdminResultController',
                'method' => 'recordResult',
                'role' => 'admin'
            ]);

            $r->addRoute('PUT', '/admin/events/{eventId:\d+}/results', [
                'controller' => 'AdminResultController',
                'method' => 'updateResult',
                'role' => 'admin'
            ]);

            $r->addRoute('POST', '/admin/events/{eventId:\d+}/scores/calculate', [
                'controller' => 'AdminResultController',
                'method' => 'calculateScores',
                'role' => 'admin'
            ]);

            $r->addRoute('POST', '/admin/participants/{participantId:\d+}/scores', [
                'controller' => 'AdminResultController',
                'method' => 'awardScore',
                'role' => 'admin'
            ]);

            // Admin - Participants
            $r->addRoute('POST', '/admin/participants', [
                'controller' => 'AdminParticipantController',
                'method' => 'createParticipant',
                'role' => 'admin'
            ]);

            $r->addRoute('POST', '/admin/participants/{participantId:\d+}/approve', [
                'controller' => 'AdminParticipantController',
                'method' => 'approveParticipant',
                'role' => 'admin'
            ]);

            // Health check
            $r->addRoute('GET', '/health', [
                'controller' => 'HealthController',
                'method' => 'check'
            ]);
        });
    }

    public function dispatch(string $httpMethod, string $uri): array
    {
        return $this->dispatcher->dispatch($httpMethod, $uri);
    }
}
