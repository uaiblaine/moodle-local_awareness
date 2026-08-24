@local @local_awareness
Feature: A notice is as hard to get past as its author asked, and never ends the session
  In order to make people read something without taking their work away
  As a site administrator
  I need each insistence level to behave the way the editor says it does

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | bilbo    | Bilbo     | Baggins  | bilbo@westfarthing.invalid |
    And I change window size to "large"
    And I log in as "admin"
    And I navigate to "Awareness > Settings" in site administration
    And I click on "Enabled" "checkbox"
    And I click on "Save changes" "button"
    And I log out

  @javascript
  Scenario: An informational notice is dismissed once and stays dismissed
    Given the following site notices exist
      | title      | content                  | insistence |
      | Tea notice | the kettle is on the way | 0          |
    When I log in as "bilbo"
    And I am on site homepage
    Then I should see "the kettle is on the way"
    And I click on "awareness-closebtn-footer" "button"
    And I am on site homepage
    Then I should not see "the kettle is on the way"
    And I should see "You are logged in as Bilbo Baggins"

  @javascript
  Scenario: A blocking notice comes back after Not now, and Accept settles it
    Given the following site notices exist
      | title       | content                     | insistence |
      | Duty notice | this one asks again politely | 1          |
    When I log in as "bilbo"
    And I am on site homepage
    Then I should see "this one asks again politely"
    # The exit is named, and it is not acceptance.
    And I click on "awareness-notnowbtn" "button"
    And I should see "You are logged in as Bilbo Baggins"
    And I am on site homepage
    # Refusing does not settle it, which is what separates Blocking from Informational.
    Then I should see "this one asks again politely"
    And I click on "awareness-acceptbtn" "button"
    And I am on site homepage
    Then I should not see "this one asks again politely"
    And I should see "You are logged in as Bilbo Baggins"

  @javascript
  Scenario: A notice requiring acknowledgement will not accept until the box is ticked
    Given the following site notices exist
      | title      | content                       | insistence |
      | Ack notice | tick the box before accepting | 2          |
    When I log in as "bilbo"
    And I am on site homepage
    Then I should see "tick the box before accepting"
    And the "awareness-acceptbtn" "button" should be disabled
    And I click on "awareness-modal-ackcheckbox" "checkbox"
    And the "awareness-acceptbtn" "button" should be enabled
    And I click on "awareness-acceptbtn" "button"
    And I am on site homepage
    Then I should not see "tick the box before accepting"
    And I should see "You are logged in as Bilbo Baggins"

  @javascript
  Scenario: Refusing a notice that requires acknowledgement leaves the session alone and asks again
    Given the following site notices exist
      | title      | content                          | insistence |
      | Ack notice | refusing this must not eject you | 2          |
    When I log in as "bilbo"
    And I am on site homepage
    Then I should see "refusing this must not eject you"
    And I click on "awareness-notnowbtn" "button"
    # The assertion this scenario has always really been about: refusing ends nothing.
    Then I should see "You are logged in as Bilbo Baggins"
    And I am on site homepage
    Then I should see "refusing this must not eject you"
