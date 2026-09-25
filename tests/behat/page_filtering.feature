@local @local_awareness
Feature: Notices are filtered by the page the user is actually on
  In order to target a notice at one part of the site
  As a site administrator
  I need the page rules to be applied to every notice that is displayed

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | bilbo    | Bilbo     | Baggins  | bilbo@westfarthing.invalid |
    And I change window size to "large"
    And I log in as "admin"
    And I navigate to "Awareness > Settings" in site administration
    And I click on "Enabled" "checkbox"
    And I click on "Save changes" "button"

  # Both pages are visited by URL, and neither is the front page: on Moodle 5.2 "/" can redirect a
  # logged-in user to /my/ before the redirect parameter is consulted (index.php, the enablemyhome
  # block), which would leave the scenario comparing the Dashboard with itself.
  #
  # Nothing is dismissed between the two pages, so only the URL changes. The closing "should see"
  # is the control for the opening "should not see": it proves the notice was live for this user,
  # so the Dashboard result was a filtering decision and not a dead pipeline.
  #
  # The module steps pin the page probe in the footer hook: on the Dashboard the module must not be
  # loaded at all, which saves the request; on the profile page it must be, or a path-restricted
  # notice could never appear anywhere.
  @javascript
  Scenario: A notice restricted to a path is shown there and nowhere else
    Given the following site notices exist
      | title        | content                            | pathmatch           |
      | Profile only | this notice belongs to the profile | /user/profile.php%  |
    When I log in as "bilbo"
    And I visit "/my/"
    Then I should not see "this notice belongs to the profile"
    And the awareness notice module should not be loaded
    And I visit "/user/profile.php"
    Then I should see "this notice belongs to the profile"
    And the awareness notice module should be loaded

  @javascript
  Scenario: A notice with no path rule is still shown everywhere
    Given the following site notices exist
      | title      | content                           |
      | Every page | this notice belongs on every page |
    When I log in as "bilbo"
    And I visit "/my/"
    Then I should see "this notice belongs on every page"
    And I visit "/user/profile.php"
    Then I should see "this notice belongs on every page"
