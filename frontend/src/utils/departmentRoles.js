/**
 * Frontend mirror of the backend's App\Support\DepartmentRoles —
 * which staff role may act on tickets/services in each department.
 * Display-only: hides an action button that the backend would already
 * reject via DepartmentRoles::userCanActOn, never a substitute for that
 * server-side check.
 */
const ROLE_BY_DEPT_CODE = {
  REG: 'Registration Staff',
  CONS: 'Doctor',
  LAB: 'Laboratory Staff',
  PHARM: 'Pharmacy Staff',
  BILL: 'Cashier/Billing Staff',
};

export function roleForDeptCode(deptCode) {
  return ROLE_BY_DEPT_CODE[deptCode] ?? null;
}

export function canActOnDepartment(activeRole, deptCode) {
  const requiredRole = roleForDeptCode(deptCode);
  return requiredRole !== null && requiredRole === activeRole;
}
