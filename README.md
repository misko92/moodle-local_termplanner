# Term planner (`local_termplanner`)

Plan a course week by week for a school term and publish the plan to the course calendar.

## What it does

- **School calendar** (Site administration › Plugins › Local plugins › School calendar): terms with their first and
  last day, plus special days typed one per line (`2026-12-23..2027-01-01 | noschool | Winter break`). Types:
  `noschool`, `early` (early release, still a school day) and `finals`. The page shows each term's numbered school
  weeks; a week without any school day (a break) gets no number.
- **Plans** (course › More › Term planner): one per group of students and term, e.g. "Chem A · Tri 2". A plan holds
  the course's activities (in course order) and free text lines, each on a school day and with an indent level.
  Labs (activities named `Lab 1.2: …`) also get a *last day to do* and a *check off by* date, filled in with the
  usual pattern: the middle school day of the next week (one day later for each other lab already due that week),
  then two school days later.
- **Checks**, shown as advice: at most 2 labs and at most 2 lab deadlines a week; a lab or test comes after the CFAs
  that precede it in its topic; items and deadlines fall on school days.
- **Publish** writes one event per week (named *prefix* W*n*, on the week's last school day, first school day, or
  spanning the week) listing the week's work for students, plus a `Lab x.y Closes` and `Lab x.y Deadline` event per
  lab. Events are course events, or one copy per chosen group. Every event a plan creates is recorded, so publishing
  again updates those events in place and removes ones no longer needed. Other calendar events are never touched.

Activity kinds come from their names: `CFA …`, `Lab <number> …`, `Test …`, `Worksheets & Notes …`.

## Requirements

Moodle 5.2 or 5.3.

## Licence

GNU GPL v3 or later.
