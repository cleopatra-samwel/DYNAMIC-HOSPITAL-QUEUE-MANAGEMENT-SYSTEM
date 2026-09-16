/**
 * Converts any status/label string to Title Case for display purposes
 * only — handles both SCREAMING_SNAKE_CASE (WAITING, IN_SERVICE) and
 * PascalCase (OnHold, Cancelled) inputs, since both forms exist in real
 * data (see ReportController's casing note). Never changes what's
 * stored or compared — display formatting only.
 */
export function formatStatusLabel(value) {
  if (value === null || value === undefined || value === '') return '';

  const words = String(value)
    .replace(/_/g, ' ')
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .trim()
    .split(/\s+/);

  return words
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
    .join(' ');
}
