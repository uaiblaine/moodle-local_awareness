@local @local_awareness @javascript
Feature: Notices that belong to a course
  In order to speak to the people in my course without asking a site administrator
  As a course author
  I need to create and manage notices from the course, and see only that course's notices

  Background:
    # C1 runs separate groups, so the group scenario below reads the mode that confines an author.
    Given the following "courses" exist:
      | fullname         | shortname | groupmode |
      | Astronomy 101    | C1        | 1         |
      | Astrophysics 201 | C2        | 0         |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher  | Terry     | Teacher  |
    # A fresh role carrying the course capability alone: nothing an archetype ships is standing in for it.
    And the following "roles" exist:
      | shortname    | name          | archetype |
      | noticeauthor | Notice author |           |
    And the following "permission overrides" exist:
      | capability                   | permission | role         | contextlevel | reference |
      | local/awareness:managecourse | Allow      | noticeauthor | Course       | C1        |
    # Enrolled as well: require_login() is a gate of its own, and the capability does not open it.
    And the following "course enrolments" exist:
      | user    | course | role         |
      | teacher | C1     | noticeauthor |
      | teacher | C2     | student      |
    And the following site notices exist
      | title            | content          | enabled | course |
      | Site-wide notice | <p>Site</p>      | 1       |        |
      | Astrophysics tip | <p>Elsewhere</p> | 1       | C2     |

  Scenario: A course author creates a notice from the course and sees only that course's notices
    Given I am on the "C1" "Course" page logged in as "teacher"
    When I navigate to "Notices" in current page administration
    # An empty list shows its empty state, not a count of zero.
    Then I should see "No notices have been created yet."
    And I should not see "Site-wide notice"
    And I should not see "Astrophysics tip"
    When I click on "Create new notice" "link"
    And I set the field "Title" to "Observatory closed on Friday"
    And I set the field "Content" to "The dome is being serviced."
    And I click on "Save changes" "button"
    Then I should see "Observatory closed on Friday"
    And I should see "Notices found: 1"
    And I should not see "Site-wide notice"

  # Only the picker and the save are here. Who RECEIVES a group notice is
  # tests/group_audience_test.php's, and it stays there on purpose: showing it in a browser means
  # switching the plugin's display on, and then the seeded notices above render as modals over the
  # course navigation and intercept the clicks these scenarios need — the headless fragility the
  # fleet's Behat rule is about. A group the save had refused would re-render the form with an
  # error instead of listing the notice, so the assertion below does prove the value was accepted.
  Scenario: A course author aims a notice at one of their own groups
    Given the following "groups" exist:
      | name | course | idnumber |
      | Red  | C1     | RED      |
      | Blue | C1     | BLUE     |
    And the following "group members" exist:
      | user    | group |
      | teacher | RED   |
    And I am on the "C1" "Course" page logged in as "teacher"
    And I navigate to "Notices" in current page administration
    And I click on "Create new notice" "link"
    And I set the field "Title" to "Red team briefing"
    And I set the field "Content" to "Meet at the red bench."
    And I set the field "Groups" to "Red"
    When I click on "Save changes" "button"
    Then I should see "Red team briefing"
    And I should see "Notices found: 1"

  Scenario: The entry is only in the course where the capability is held
    Given I am on the "C2" "Course" page logged in as "teacher"
    Then "Notices" "link" should not exist in current page administration

  Scenario: The site list names the course a notice belongs to
    Given the following site notices exist
      | title          | content         | enabled | course |
      | Star party     | <p>Tonight</p>  | 1       | C1     |
    And I log in as "admin"
    When I navigate to "Awareness > Manage" in site administration
    Then I should see "Star party"
    And I should see "Course: Astronomy 101"
    And I should see "Course: Astrophysics 201"
    And I should see "Site-wide notice"
