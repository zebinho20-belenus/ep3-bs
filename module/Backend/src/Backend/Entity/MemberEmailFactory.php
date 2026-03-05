<?php

namespace Backend\Entity;

use Base\Entity\AbstractEntityFactory;

class MemberEmailFactory extends AbstractEntityFactory
{

    protected static $entityClass = 'Backend\Entity\MemberEmail';
    protected static $entityPrimary = 'meid';

}