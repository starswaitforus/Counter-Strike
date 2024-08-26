<?php

namespace cs\Interface;

interface Flammable extends Volumetric
{

    public function getMaxAreaMetersSquared(): int;

    public function getSpawnAreaMetersSquared(): int;

    public function getMaxTimeMs(): int;

    public function getBoundingRadius(): int;

    public function calculateDamage(bool $hasKevlar): int;

}
