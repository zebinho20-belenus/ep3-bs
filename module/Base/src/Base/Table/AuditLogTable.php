<?php

namespace Base\Table;

use Zend\Db\TableGateway\TableGateway;

class AuditLogTable extends TableGateway
{
    const NAME = 'bs_audit_log';
}
