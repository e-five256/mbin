<?php

declare(strict_types=1);

namespace App\MessageHandler\ActivityPub\Inbox;

use App\Entity\Magazine;
use App\Entity\User;
use App\Message\ActivityPub\Inbox\FollowMessage;
use App\Service\ActivityPub\ApHttpClient;
use App\Service\ActivityPub\Wrapper\AcceptWrapper;
use App\Service\ActivityPubManager;
use App\Service\MagazineManager;
use App\Service\UserManager;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FollowHandler
{
    public function __construct(
        private readonly ActivityPubManager $activityPubManager,
        private readonly UserManager $userManager,
        private readonly MagazineManager $magazineManager,
        private readonly ApHttpClient $client,
        private readonly AcceptWrapper $acceptWrapper
    ) {
    }

    public function __invoke(FollowMessage $message)
    {
        $actor = $this->activityPubManager->findActorOrCreate($message->payload['actor']);
        // Check if actor is not empty
        if (!empty($actor)) {
            if ('Follow' === $message->payload['type']) {
                $object = $this->activityPubManager->findActorOrCreate($message->payload['object']);
                // Check if object is not empty
                if (!empty($object)) {
                    $this->handleFollow($object, $actor);

                    // @todo manually Accept
                    $this->accept($message->payload, $object);
                }

                return;
            }

            if (isset($message->payload['object'])) {
                switch ($message->payload['type']) {
                    case 'Undo':
                        $this->handleUnfollow(
                            $actor,
                            $this->activityPubManager->findActorOrCreate($message->payload['object']['object'])
                        );
                        break;
                    case 'Accept':
                        $this->handleAccept(
                            $actor,
                            $this->activityPubManager->findActorOrCreate($message->payload['object']['actor'])
                        );
                        break;
                    case 'Reject':
                        $this->handleReject(
                            $actor,
                            $this->activityPubManager->findActorOrCreate($message->payload['object']['actor'])
                        );
                        break;
                    default:
                        break;
                }
            }
        }
    }

    private function handleFollow(User|Magazine $object, User $actor): void
    {
        match (true) {
            $object instanceof User => $this->userManager->follow($actor, $object),
            $object instanceof Magazine => $this->magazineManager->subscribe($object, $actor),
            default => throw new \LogicException(),
        };
    }

    #[ArrayShape([
        '@context' => 'string',
        'id' => 'string',
        'type' => 'string',
        'actor' => 'mixed',
        'object' => 'mixed',
    ])]
    private function accept(
        array $payload,
        User|Magazine $object
    ): void {
        $accept = $this->acceptWrapper->build(
            $payload['object'],
            $payload['actor'],
            $payload['id'],
        );

        $this->client->post($this->client->getInboxUrl($payload['actor']), $object, $accept);
    }

    private function handleUnfollow(User $actor, null|User|Magazine $object): void
    {
        if (!empty($object)) {
            match (true) {
                $object instanceof User => $this->userManager->unfollow($actor, $object),
                $object instanceof Magazine => $this->magazineManager->unsubscribe($object, $actor),
                default => throw new \LogicException(),
            };
        }
    }

    private function handleAccept(User $actor, null|User|Magazine $object): void
    {
        if (!empty($object)) {
            if ($object instanceof User) {
                $this->userManager->acceptFollow($object, $actor);
            }

            //        if ($object instanceof Magazine) {
            //            $this->magazineManager->acceptFollow($actor, $object);
            //        }
        }
    }

    private function handleReject(User $actor, null|User|Magazine $object): void
    {
        if (!empty($object)) {
            match (true) {
                $object instanceof User => $this->userManager->rejectFollow($object, $actor),
                $object instanceof Magazine => $this->magazineManager->unsubscribe($object, $actor),
                default => throw new \LogicException(),
            };
        }
    }
}
