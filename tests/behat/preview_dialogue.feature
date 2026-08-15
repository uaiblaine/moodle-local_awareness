@local @local_awareness @javascript
Feature: Previewing a notice from the editor
  In order to judge a notice before anybody else is shown it
  As an administrator
  I need the editor to show me the notice as a dialogue

  Background:
    Given I log in as "admin"
    And I navigate to "Awareness > Manage" in site administration
    And I click on "Create new notice" "link"

  Scenario: The preview is nowhere on the page until it is asked for
    # The markup waits in a template element, which is genuinely inert - not laid out, not
    # focusable, not announced. The editor's previous shape kept content off screen with a 1x1
    # clip instead, which is the technique whose whole purpose is to keep content available to
    # assistive technology, and so shipped fields a keyboard could reach and an eye could not find.
    Then I should not see "Frequency"
    When I click on "Preview" "button"
    Then I should see "Frequency" in the "Preview" "dialogue"

  Scenario: The dialogue shows what the form currently says
    When I set the field "Title" to "Scheduled maintenance"
    And I click on "Preview" "button"
    Then I should see "Scheduled maintenance" in the "Preview" "dialogue"

  Scenario: Both viewport sizes are offered
    When I click on "Preview" "button"
    Then I should see "Desktop" in the "Preview" "dialogue"
    And I should see "Mobile" in the "Preview" "dialogue"

  Scenario: The chosen viewport survives closing and reopening the dialogue
    # The dialogue is built once and hidden rather than destroyed, so the pressed state of these
    # toggles outlives a close. It used to outlive the width that went with it: reopening redrew the
    # mock at the form's desktop width while Mobile still reported aria-pressed="true", telling a
    # screen reader that a viewport was selected which was not on screen. Both halves are asserted
    # because the button alone was already right when the bug was live.
    When I click on "Preview" "button"
    And I click on "Mobile" "button" in the "Preview" "dialogue"
    Then the "style" attribute of ".la-mockmodal" "css_element" should contain "240px"
    When I press the escape key
    And I click on "Preview" "button"
    Then the "aria-pressed" attribute of ".la-preview-tab[data-tab='mobile']" "css_element" should contain "true"
    And the "style" attribute of ".la-mockmodal" "css_element" should contain "240px"

  Scenario: The dialogue closes on Escape
    # Escape, the focus trap and the return of focus to the button that opened it all come from
    # core/modal, and using core/modal rather than a hand-made dialogue is the entire reason this
    # page's JavaScript knows what a modal is. Asserted here so that swapping it out cannot be a
    # silent change: a hand-made dialogue passes every other gate in the pipeline.
    When I click on "Preview" "button"
    Then I should see "Frequency" in the "Preview" "dialogue"
    When I press the escape key
    Then I should not see "Frequency"
