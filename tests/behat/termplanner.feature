@local @local_termplanner
Feature: Plan a term and publish it to the course calendar
  In order to give students a week-by-week list of work
  As a teacher
  I need to plan weeks in a term and publish them to the calendar

  Background:
    Given the following "courses" exist:
      | fullname  | shortname |
      | Chemistry | CHEM      |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CHEM   | editingteacher |
    And the following "activities" exist:
      | activity | course | section | name                        |
      | quiz     | CHEM   | 1       | CFA Section 1.1: Units      |
      | quiz     | CHEM   | 1       | Lab 1.1: Measuring & Errors |

  Scenario: The school calendar shows special days in a term's weeks
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > School calendar" in site administration
    And I press "New term"
    And I set the following fields to these values:
      | Term name    | Tri 8                                                         |
      | First day    | 2030-01-07                                                    |
      | Last day     | 2030-01-25                                                    |
      | Special days | 2030-01-21 \| noschool \| Holiday                              |
    And I press "Save changes"
    Then I should see "Tri 8"
    And I should see "No school"
    And I should see "Holiday"
    And I should see "4 school days"

  Scenario: Plan a week and publish it to the calendar
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > School calendar" in site administration
    And I press "New term"
    And I set the following fields to these values:
      | Term name | Tri 9                                   |
      | First day | ##monday next week##%Y-%m-%d##          |
      | Last day  | ##monday next week +18 days##%Y-%m-%d## |
    And I press "Save changes"
    And I log out
    When I log in as "teacher1"
    And I am on "Chemistry" course homepage
    And I navigate to "Term planner" in current page administration
    And I press "New plan"
    And I set the following fields to these values:
      | Plan name  | Chem · Tri 9 |
      | Event name | Chem T9      |
    And I press "Save changes"
    And I follow "List view"
    Then I should see "Week 1"
    And I should see "Week 3"
    And I set the field "addcm[1]" to "CFA Section 1.1: Units"
    And I set the field "addtext[1]" to "Read sections 1 and 2"
    And I click on "Add" "button" in the "#week1" "css_element"
    And I set the field "addcm[1]" to "Lab 1.1: Measuring & Errors"
    And I click on "Add" "button" in the "#week1" "css_element"
    And I should see "All checks pass"
    And I should see "Do CFA" in the "#week1" "css_element"
    And I should see "Check off Lab 1.1 with your teacher" in the "#week1" "css_element"
    And I should see "Lab 1.1: last day to do the lab" in the "#week2" "css_element"
    And I press "Publish to calendar"
    And I should see "4 to create"
    And I press "Publish"
    And I should see "Published: 4 created"
    And I am viewing calendar in "upcoming" view
    And I should see "Lab 1.1 Deadline"

  @javascript
  Scenario: Use the week grid to add, move and publish
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > School calendar" in site administration
    And I press "New term"
    And I set the following fields to these values:
      | Term name | Tri 9                                   |
      | First day | ##monday next week##%Y-%m-%d##          |
      | Last day  | ##monday next week +18 days##%Y-%m-%d## |
    And I press "Save changes"
    And I log out
    When I log in as "teacher1"
    And I am on "Chemistry" course homepage
    And I navigate to "Term planner" in current page administration
    And I press "New plan"
    And I set the following fields to these values:
      | Plan name  | Chem · Tri 9 |
      | Event name | Chem T9      |
    And I press "Save changes"
    # Click Monday of week 1 to open its panel, then add an activity and a line of text.
    And I click on "#tp-week1 + .tp-day .tp-date" "css_element"
    And I set the field with xpath "//div[@data-region='detail']//select[1]" to "CFA Section 1.1: Units"
    And I set the field with xpath "//div[@data-region='detail']//input[@type='text']" to "Read sections 1 and 2"
    And I click on "Add" "button" in the "[data-region='detail']" "css_element"
    Then I should see "CFA 1.1" in the "#tp-week1 + .tp-day" "css_element"
    And I should see "Read sections 1 and 2" in the "#tp-week1 + .tp-day" "css_element"
    And I click on "#tp-week1 + .tp-day + .tp-day .tp-date" "css_element"
    # Typing in the search box narrows the activity menu; a single match is picked straight away.
    And I set the field with xpath "//div[@data-region='detail']//input[@type='search']" to "lab"
    And I click on "Add" "button" in the "[data-region='detail']" "css_element"
    And I should see "Lab 1.1" in the "#tp-week1 + .tp-day + .tp-day" "css_element"
    And I should see "Lab 1.1 last" in the "#tp-week2 + .tp-day + .tp-day + .tp-day" "css_element"
    # The lab is before its CFA? No: CFA on Monday, lab on Tuesday.
    And I should see "All checks pass"
    # Move the CFA a day later from the side panel: now the lab comes first and the check says so.
    And I click on "CFA 1.1" "text" in the "[data-region='grid']" "css_element"
    And I click on "1 school day ▶" "button" in the "[data-region='detail']" "css_element"
    And I should see "CFA 1.1" in the "#tp-week1 + .tp-day + .tp-day" "css_element"
    And I should see "Lab 1.1 comes before CFA Section 1.1"
    # The week preview shows what students get.
    And I click on "#tp-week1 .tp-wk" "css_element"
    And I should see "Check off Lab 1.1 with your teacher" in the "[data-region='detail']" "css_element"
    And I click on "Publish to calendar" "link"
    And I press "Publish"
    And I should see "Published:"
    And I should see "CFA 1.1" in the "[data-region='grid']" "css_element"
