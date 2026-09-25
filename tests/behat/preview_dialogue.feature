@local @local_awareness @javascript
Feature: Previewing a notice from the editor
  In order to judge a notice before anybody else is shown it
  As an administrator
  I need to see the notice itself, not a drawing of it

  Background:
    Given I log in as "admin"
    And I navigate to "Awareness > Manage" in site administration
    And I click on "Create new notice" "link"

  Scenario: The preview shows the content the form currently holds
    # The preview is the content itself, rendered by the server and opened in the real notice
    # dialogue (local_awareness/modal_notice), the same one the manage list opens for a saved notice.
    When I set the field "Title" to "Scheduled maintenance"
    And I set the field "Content" to "The library closes at six."
    And I click on "Preview" "button"
    Then I should see "The library closes at six." in the "Scheduled maintenance" "dialogue"

  Scenario: An empty notice says so rather than showing an empty box
    When I click on "Preview" "button"
    Then I should see "This notice has no content yet."

  Scenario: The dialogue closes on Escape
    # local_awareness/modal_notice routes Escape to the close button, and core/modal underneath it
    # traps focus. Asserted so that replacing the dialogue with a hand-made one cannot be a silent
    # change: nothing else here would notice.
    When I set the field "Title" to "Scheduled maintenance"
    And I set the field "Content" to "The library closes at six."
    And I click on "Preview" "button"
    Then I should see "The library closes at six."
    When I press the escape key
    Then I should not see "The library closes at six."
