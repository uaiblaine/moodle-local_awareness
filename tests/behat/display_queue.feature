@local @local_awareness
Feature: Notices are shown one at a time
  In order to get on with what I came to the site to do
  As a user
  I need one notice in front of me at a time, not a stack of them

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | bilbo    | Bilbo     | Baggins  | bilbo@westfarthing.invalid |
    And I change window size to "large"
    And I log in as "admin"
    And I navigate to "Awareness > Settings" in site administration
    And I click on "Enabled" "checkbox"
    And I click on "Save changes" "button"

  # Observed from the page login lands on. That page already takes the first turn in the queue, so
  # navigating somewhere else first would spend it and leave the scenario asserting against the
  # second notice while reading like the first.
  #
  # The load-bearing assertion is the one after the dismissal. The module renders one notice at a
  # time, so a second notice unrendered in the payload is invisible either way; what the queue
  # changes is that nothing more arrives until the user reaches a page again. Changes that must make
  # it fail: removing the queue, caught by the step after the dismissal.
  @javascript
  Scenario: A second notice waits until the user meets it again
    Given the following site notices exist
      | title | content                   |
      | One   | this is the first notice  |
      | Two   | this is the second notice |
    When I log in as "bilbo"
    Then I should see "this is the first notice"
    And I click on "awareness-closebtn" "button"
    Then I should not see "this is the second notice"
    And I visit "/my/"
    Then I should see "this is the second notice"

  # Repeating notices meeting the user for the first time are the one exception: deferring one
  # behind the other only delays something that is going to interrupt again anyway. Both are
  # delivered in the same response, so the second appears without navigating anywhere.
  @javascript
  Scenario: Two repeating notices meeting the user for the first time arrive together
    Given the following site notices exist
      | title | content                    | resetinterval |
      | Rep A | this repeating notice is A | 86400         |
      | Rep B | this repeating notice is B | 86400         |
    When I log in as "bilbo"
    Then I should see "this repeating notice is A"
    And I click on "awareness-closebtn" "button"
    Then I should see "this repeating notice is B"
