import dayjs from 'dayjs';

/** Age isn't a stored field anywhere (Patient only has date_of_birth) — this is the one place it's computed from DOB. */
export function ageFromDob(dob) {
  return dob ? dayjs().diff(dayjs(dob), 'year') : null;
}
