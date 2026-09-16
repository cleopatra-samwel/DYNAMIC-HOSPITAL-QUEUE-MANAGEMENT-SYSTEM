/**
 * The fixed set of categories on a standard lab request form — shared by
 * the Administrator's Lab Test Catalog page (assigning a category to each
 * item) and the Doctor/Laboratory Staff checklist (grouping items under
 * these same headers, in this same order). Not enforced at the database
 * level (lab_test_catalog.category is a plain string) so an
 * uncategorized/legacy item never disappears — it just falls into the
 * "Other" bucket everywhere this list is used.
 */
export const LAB_TEST_CATEGORIES = [
  'Hematology',
  'Coagulation Tests',
  'Urine Examination',
  'Stool Examination',
  'Clinical Chemistry',
  'Diagnostics',
  'Radiology',
];

export const UNCATEGORIZED_LABEL = 'Other';

/** Groups catalog items by category, in LAB_TEST_CATEGORIES order, with anything else (or no category) collected last under "Other". */
export function groupByCategory(items) {
  const byCategory = new Map(LAB_TEST_CATEGORIES.map((c) => [c, []]));
  const other = [];

  for (const item of items) {
    if (item.category && byCategory.has(item.category)) {
      byCategory.get(item.category).push(item);
    } else {
      other.push(item);
    }
  }

  const groups = LAB_TEST_CATEGORIES
    .map((category) => ({ category, items: byCategory.get(category) }))
    .filter((group) => group.items.length > 0);

  if (other.length > 0) {
    groups.push({ category: UNCATEGORIZED_LABEL, items: other });
  }

  return groups;
}
