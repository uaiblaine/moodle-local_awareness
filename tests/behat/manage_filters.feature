@local @local_awareness @javascript
Feature: Filtering the notice list
  In order to find a notice on a site that has many
  As an administrator
  I need the list to narrow by name, status and validity without reloading the page

  Background:
    Given the following site notices exist
      | title                   | content              | enabled | pathmatch          | resetinterval |
      | Manutenção programada   | <p>Maintenance</p>   | 1       | /course/view.php   | 86400         |
      | Nova política de senhas | <p>Passwords</p>     | 1       | /course/view.php   | 86400         |
      | Pesquisa de satisfação  | <p>Survey</p>        | 0       | /my/               | 0             |
    And I log in as "admin"
    And I navigate to "Awareness > Manage" in site administration

  Scenario: The list starts unfiltered
    Then I should see "Manutenção programada"
    And I should see "Nova política de senhas"
    And I should see "Pesquisa de satisfação"
    And I should see "Notices found: 3"

  Scenario: Narrowing by status leaves only the drafts
    When I set the field "Status" to "Draft"
    Then I should see "Pesquisa de satisfação"
    And I should not see "Manutenção programada"
    And I should not see "Nova política de senhas"

  Scenario: Narrowing by status leaves only the competing notices
    # The two repeating notices reach the same pages; the third repeats never, so it cannot compete.
    When I set the field "Status" to "Competing"
    Then I should see "Manutenção programada"
    And I should see "Nova política de senhas"
    And I should not see "Pesquisa de satisfação"

  Scenario: Searching by name ignores accents
    # Asserted through the result count rather than "should not see" on the other title: the
    # conflict badge on the surviving row names its rival inside a visually-hidden explanation, so
    # that title is legitimately present in the page text even when its row is gone. That hidden
    # text is the point of the badge — it is what a screen reader announces — and it makes any
    # whole-page negative assertion near it unreliable.
    When I set the field "Search by name" to "manutencao"
    Then I should see "Manutenção programada"
    And I should see "Notices found: 1"

  Scenario: A name filter arriving in the URL survives the first touch of another control
    # managenotice.php accepts the filter values as URL parameters so a filtered list can be linked
    # to, and the server honoured them — but the search box rendered empty, because the value was
    # exported to the template and no markup consumed it. The reader then saw a short list with no
    # visible reason, and the first touch of Status made manage_list.js read the empty box and push
    # a filterset without the name, silently widening the list back to everything.
    When I visit "/local/awareness/managenotice.php?name=manutencao"
    Then the field "Search by name" matches value "manutencao"
    And I should see "Notices found: 1"
    # The way out of a filter has to be offered on arrival, not only once something is touched.
    And I should see "Clear filters"
    When I set the field "Status" to "Active"
    Then I should see "Notices found: 1"
    And I should see "Manutenção programada"

  Scenario: A search that matches nothing offers a way back
    When I set the field "Search by name" to "zzzznothing"
    Then I should see "No notices match these filters."
    And I should not see "Manutenção programada"
    When I click on "Clear filters" "button"
    Then I should see "Manutenção programada"
    And I should see "Pesquisa de satisfação"
