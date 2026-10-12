// frontend/e2e/fixtures/reserve.js
//
// The e2e database is reset once per run (global-setup.js), not per test, and global data (shifts
// of a month, delegations, closures, availability, newbie periods, checklist submissions) is seen
// by every spec. Each spec therefore owns the months, days and names it writes data into, and
// claims them here at the top of the file:
//
//   const reserve = reservationsFor('schedule/move-assignment');
//   const CANCELLED_MONTH = reserve.month('2095-01');
//   const SUBMITTED_ON = reserve.day('2097-05-10'); // a day inside a month several files share
//   const GROUP = reserve.name('Drop Twice Team');
//   reserve.months('2095-02', '2095-03'); // used inline by the tests below
//
// A second claim of the same month, day or name in one run throws, naming both owners, so a clash
// fails loudly instead of as a flaky assertion somewhere else. A month claim also clashes with any
// day claimed inside it by another file (and vice versa); days let several files share a month.
//
// Claims run when a spec file is loaded. Playwright loads every spec file in the runner process to
// list the tests, so on a full run (or a -g/--grep run) a clash between any two files stops the run
// before a test starts. A run limited to some files (`npx playwright test e2e/schedule`) loads only
// those, so it only sees clashes among them.
const owners = new Map();

const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;
const DAY = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/;

function claim(key, owner) {
  const previous = owners.get(key);
  if (previous !== undefined) {
    throw new Error(
      `e2e reservation clash: ${key} is claimed by "${owner}" but already reserved by ` +
        `"${previous}". Pick an unused one (see e2e/fixtures/reserve.js).`,
    );
  }
  owners.set(key, owner);
}

function ownerOfDaysIn(month, except) {
  for (const [key, owner] of owners) {
    if (key.startsWith(`day:${month}-`) && owner !== except) return owner;
  }
  return undefined;
}

export function reservationsFor(owner) {
  return {
    month(month) {
      if (!MONTH.test(month)) throw new Error(`reserve.month("${month}"): expected YYYY-MM`);
      const dayOwner = ownerOfDaysIn(month, owner);
      if (dayOwner !== undefined) {
        throw new Error(
          `e2e reservation clash: month ${month} is claimed by "${owner}" but "${dayOwner}" ` +
            'already reserved days in it.',
        );
      }
      claim(`month:${month}`, owner);
      return month;
    },
    day(day) {
      if (!DAY.test(day)) throw new Error(`reserve.day("${day}"): expected YYYY-MM-DD`);
      const monthOwner = owners.get(`month:${day.slice(0, 7)}`);
      if (monthOwner !== undefined && monthOwner !== owner) {
        throw new Error(
          `e2e reservation clash: day ${day} is claimed by "${owner}" but its month is reserved ` +
            `by "${monthOwner}".`,
        );
      }
      claim(`day:${day}`, owner);
      return day;
    },
    name(name) {
      claim(`name:${name}`, owner);
      return name;
    },
    months(...months) {
      return months.map((month) => this.month(month));
    },
    days(...days) {
      return days.map((day) => this.day(day));
    },
  };
}
