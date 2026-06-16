@local @local_awareness @local_awareness_audience
Feature: The audience estimate panel calculates how many users a notice will reach
  In order to know the impact of a notice before publishing it
  As a site administrator
  I need to see an asynchronous audience-size estimate based on the configured filters

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | u1       | One       | Member   | u1@example.invalid   |
      | u2       | Two       | Member   | u2@example.invalid   |
      | u3       | Three     | Member   | u3@example.invalid   |
    And the following "cohorts" exist:
      | name           | idnumber |
      | Audience pilot | aud1     |
    And the following "cohort members" exist:
      | user | cohort |
      | u1   | aud1   |
      | u2   | aud1   |
    And I change window size to "large"
    And I log in as "admin"

  @javascript
  Scenario: The estimator queues a job and renders the result after the queue runs
    When I navigate to "Awareness > Manage" in site administration
    And I press "Create new notice"
    And I wait until ".local-awareness-editor" "css_element" exists
    # Add the cohort. The autocomplete is the first audience field.
    And I set the field "Cohort" to "Audience pilot"
    # Title is required; set it so the form would also be valid for save.
    And I set the field "Title" to "Estimator scenario"
    # Trigger the estimator manually to keep the test deterministic.
    And I press "Calculate reach"
    And I run all adhoc tasks
    # Re-trigger so the panel polls and renders the cached result.
    And I press "Calculate reach"
    Then I should see "2" in the ".la-audience-reach-value" "css_element"
