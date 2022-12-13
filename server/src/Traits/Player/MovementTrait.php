<?php

namespace cs\Traits\Player;

use cs\Core\GameException;
use cs\Core\Point;
use cs\Core\Setting;
use cs\Core\Util;
use cs\Enum\ItemType;
use cs\Enum\SoundType;
use cs\Event\PlayerMovementEvent;
use cs\Event\SoundEvent;

trait MovementTrait
{

    private int $moveX = 0;
    private int $moveZ = 0;
    private int $lastMoveX = 0;
    private int $lastMoveZ = 0;
    private ?int $lastAngle = 0;
    private bool $isWalking = false;

    public function speedRun(): void
    {
        $this->isWalking = false;
    }

    public function speedWalk(): void
    {
        $this->isWalking = true;
    }

    public function isMoving(): bool
    {
        return ($this->moveX <> 0 || $this->moveZ <> 0);
    }

    public function isWalking(): bool
    {
        return $this->isWalking;
    }

    public function isRunning(): bool
    {
        return !$this->isWalking;
    }

    public function getPositionClone(): Point
    {
        return $this->position->clone();
    }

    public function getReferenceToPosition(): Point
    {
        return $this->position;
    }

    public function setPosition(Point $newPosition): void
    {
        $this->stop();
        $this->position->setFrom($newPosition);
        $this->setActiveFloor($this->world->findFloor($this->position, $this->playerBoundingRadius));
    }

    public function stop(): void
    {
        $this->lastMoveX = $this->moveX;
        $this->lastMoveZ = $this->moveZ;
        $this->moveX = 0;
        $this->moveZ = 0;
    }

    public function moveForward(): void
    {
        $this->moveZ = 1;
    }

    public function moveRight(): void
    {
        $this->moveX = 1;
    }

    public function moveLeft(): void
    {
        $this->moveX = -1;
    }

    public function moveBackward(): void
    {
        $this->moveZ = -1;
    }

    protected function createMovementEvent(): PlayerMovementEvent
    {
        return new PlayerMovementEvent(function (): void {
            if (!$this->isMoving()) {
                return;
            }

            $this->position->setFrom($this->processMovement($this->moveX, $this->moveZ, $this->position));
            $this->world->tryPickDropItems($this);
            $this->stop();
        });
    }

    private function getMoveAngle(): float
    {
        $moveX = $this->moveX;
        $moveZ = $this->moveZ;
        $angle = $this->sight->getRotationHorizontal();

        if ($moveX <> 0 && $moveZ <> 0) { // diagonal move
            if ($moveZ === 1) {
                $angle += $moveX * 45;
            } else {
                $angle += $moveX * (45 * 3);
            }
        } else { // single direction move
            if ($moveZ === -1) {
                $angle += 180;
            } elseif ($moveX === 1) {
                $angle += 90;
            } elseif ($moveX === -1) {
                $angle += -90;
            }
        }

        return $angle;
    }

    private function getMoveSpeed(): int
    {
        if ($this->isCrouching()) {
            $speed = Setting::moveDistanceCrouchPerTick();
        } elseif ($this->isWalking()) {
            $speed = Setting::moveDistanceWalkPerTick();
        } elseif ($this->isRunning()) {
            $speed = Setting::moveDistancePerTick();
        } else {
            throw new GameException("Wat doing?");
        }

        $equippedItem = $this->getEquippedItem();
        if ($equippedItem->getType() === ItemType::TYPE_WEAPON_PRIMARY) {
            $speed *= Setting::getWeaponPrimarySpeedMultiplier($equippedItem->getId());
        } elseif ($equippedItem->getType() === ItemType::TYPE_WEAPON_SECONDARY) {
            $speed *= Setting::getWeaponSecondarySpeedMultiplier($equippedItem->getId());
        }
        if ($this->isJumping()) {
            $speed *= Setting::jumpMovementSpeedMultiplier();
        } elseif ($this->isFlying()) {
            $speed *= Setting::flyingMovementSpeedMultiplier();
        }

        return (int)ceil($speed);
    }

    private function processMovement(int $moveX, int $moveZ, Point $current): Point
    {
        // If single direction move in opposite direction than previous (counter strafing) we stop
        if (!($moveX <> 0 && $moveZ <> 0) && (($moveX !== 0 && $this->lastMoveX === -$moveX) || ($moveZ !== 0 && $this->lastMoveZ === -$moveZ))) {
            return $current;
        }

        $distanceTarget = $this->getMoveSpeed();
        $angle = Util::normalizeAngle($this->getMoveAngle());
        $angleInt = Util::nearbyInt($angle);

        if ($this->isFlying()) {
            if ($this->lastAngle === null) {
                return $current;
            }
            if (abs(Util::smallestDeltaAngle($this->lastAngle, $angleInt)) > 160) { // stop if drastically changing direction in air
                $this->lastAngle = null;
                return $current;
            }
        }
        $this->lastAngle = $angleInt;

        $looseFloor = false;
        $orig = $current->clone();
        $target = $orig->clone();
        $candidate = $target->clone();
        for ($i = 1; $i <= $distanceTarget; $i++) {
            [$x, $z] = Util::movementXZ($angle, $i);
            $candidate->setX($orig->x + $x)->setZ($orig->z + $z);
            if ($candidate->equals($target)) {
                continue;
            }

            $canMove = $this->canMoveTo($target, $candidate, $angleInt);
            if (!$canMove) {
                if ($canMove === null) { // if move is possible in one axis at least
                    $target->setFrom($candidate);
                }
                break;
            }

            $target->setFrom($candidate);
            if ($this->activeFloor && !$this->world->isOnFloor($this->activeFloor, $target, $this->getBoundingRadius())) {
                $this->setActiveFloor(null);
            }
            if (!$looseFloor && !$this->activeFloor && !$this->isJumping()) { // do initial (one-shot) gravity bump
                $newY = $this->calculateGravity($target, 1);
                $candidate->setY($newY);
                $target->setY($newY);
                $looseFloor = true;
            }
        }

        if ($this->isRunning() && !$this->isCrouching() && !$this->isFlying() && !$orig->equals($target)) {
            $sound = new SoundEvent($target, SoundType::PLAYER_STEP);
            $this->world->makeSound($sound->setPlayer($this));
        }

        return $target;
    }

    private function canMoveTo(Point $start, Point $candidate, int $angle): ?bool
    {
        $radius = $this->playerBoundingRadius;
        if ($this->collisionWithPlayer($candidate, $radius)) {
            return false;
        }
        $height = $this->headHeight;
        $maxWallCeiling = $candidate->y + Setting::playerObstacleOvercomeHeight();

        $xWallMaxHeight = 0;
        if ($start->x <> $candidate->x) {
            $xGrowing = ($start->x < $candidate->x);
            $baseX = $candidate->clone()->addX($xGrowing ? $radius : -$radius);
            $xWallMaxHeight = $this->world->findHighestXWall($baseX, $height, $radius, $maxWallCeiling);
        }
        $zWallMaxHeight = 0;
        if ($start->z <> $candidate->z) {
            $zGrowing = ($start->z < $candidate->z);
            $baseZ = $candidate->clone()->addZ($zGrowing ? $radius : -$radius);
            $zWallMaxHeight = $this->world->findHighestZWall($baseZ, $height, $radius, $maxWallCeiling);
        }
        if ($xWallMaxHeight === 0 && $zWallMaxHeight === 0) { // no walls
            return true;
        }

        if ($this->isFlying()) {
            return false;
        }

        // Try step over ONE low height wall
        if ($xWallMaxHeight === 0 && $zWallMaxHeight <= $maxWallCeiling) {
            $highestWallCeiling = $zWallMaxHeight;
        } elseif ($zWallMaxHeight === 0 && $xWallMaxHeight <= $maxWallCeiling) {
            $highestWallCeiling = $xWallMaxHeight;
        } else {
            return false; // Tall walls everywhere
        }
        if ($highestWallCeiling !== 0) {
            $floor = $this->world->findFloor($candidate->clone()->setY($highestWallCeiling), $radius);
            if ($floor) {
                $candidateY = $candidate->clone()->setY($floor->getY());
                if (!$this->collisionWithPlayer($candidateY, $radius)) {
                    $candidate->setY($floor->getY()); // side effect
                    $this->setActiveFloor($floor);
                    return true;
                }
            }
        }

        // If moving in 90s angles against wall we stop
        if ($angle % 90 === 0) {
            return false;
        }

        // Try to move 1 unit in one axis at least if possible
        if ($xWallMaxHeight === 0) { // Try to move in X axis
            $oneSideCandidate = $candidate->clone()->setZ($start->z); // reset to previous Z
            $oneSideCandidate->addX($angle > 180 ? -1 : 1); // try 1 unit in X
            $oneSideCandidateX = $oneSideCandidate->clone()->addX($angle > 180 ? -$radius : $radius);
            $xWall = $this->world->checkXSideWallCollision($oneSideCandidateX, $height, $radius);
            if (!$xWall && !$this->collisionWithPlayer($oneSideCandidate, $radius)) {
                $candidate->setFrom($oneSideCandidate); // side effect
                return null;
            }
            return false;
        }
        if ($zWallMaxHeight === 0) { // Try to move in Z axis
            $oneSideCandidate = $candidate->clone()->setX($start->x); // reset to previous X
            $oneSideCandidate->addZ(($angle > 270 || $angle < 90) ? 1 : -1); // try 1 unit in Z
            $oneSideCandidateZ = $oneSideCandidate->clone()->addZ(($angle > 270 || $angle < 90) ? $radius : -$radius);
            $zWall = $this->world->checkZSideWallCollision($oneSideCandidateZ, $height, $radius);
            if (!$zWall && !$this->collisionWithPlayer($oneSideCandidate, $radius)) {
                $candidate->setFrom($oneSideCandidate); // side effect
                return null;
            }
            return false;
        }

        return false;
    }

    private function collisionWithPlayer(Point $candidate, int $radius): bool
    {
        return (null !== $this->world->isCollisionWithOtherPlayers($this->id, $candidate, $radius, $this->headHeight));
    }


}
