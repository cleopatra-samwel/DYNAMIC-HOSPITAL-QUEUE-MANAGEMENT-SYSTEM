<?php

namespace App\Support;

/**
 * Which department-to-department transfers are a normal part of the
 * patient journey, keyed by dept_code. A transfer to a target NOT listed
 * for the current department is a workflow-skip and is rejected unless
 * explicitly authorized (see ServiceFlowController::requestNextService).
 *
 * REG isn't listed as a "from" here — Registration itself isn't a ticketed
 * queue stage (the registering staff picks the patient's first destination
 * department directly when the visit is created), so the journey's first
 * real hop starts from whatever department that first service is in.
 */
class DepartmentWorkflow
{
    private const ALLOWED_NEXT = [
        'CONS' => ['LAB', 'PHARM'],
        'LAB' => ['CONS'],
        'PHARM' => [],
        'BILL' => [],
    ];

    public static function isAllowed(string $fromDeptCode, string $toDeptCode): bool
    {
        return in_array($toDeptCode, self::ALLOWED_NEXT[$fromDeptCode] ?? [], true);
    }
}
