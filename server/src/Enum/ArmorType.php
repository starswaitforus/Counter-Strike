<?php

namespace cs\Enum;

enum ArmorType: int
{

    case NONE = 0;
    case BODY = 1;
    case BODY_AND_HEAD = 2;

    public function hasArmorHead(): bool
    {
        return ($this === self::BODY_AND_HEAD);
    }

    public function hasArmor(): bool
    {
        return ($this === self::BODY);
    }

}
