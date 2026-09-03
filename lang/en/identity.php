<?php

/*
 * Account administration strings.
 *
 * The role vocabulary is the provisional least-privilege one in
 * docs/01-product-scope.md. Hydromet has not approved the final matrix or the
 * list of people who hold each role (docs/08-hydromet-input-checklist.md,
 * section 6), so these labels describe what the portal currently enforces, not
 * an agreed authorization model.
 */

return [
    'navigation_group' => 'Identity',
    'account' => 'User account',
    'accounts' => 'User accounts',

    'roles' => [
        'administrator' => 'Administrator',
        'operator' => 'Operator',
        'editor' => 'Editor',
    ],

    'fields' => [
        'name' => 'Name',
        'email' => 'E-mail',
        'role' => 'Role',
        'is_active' => 'Active',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'created_at' => 'Created',
        'updated_at' => 'Updated',
    ],

    'sections' => [
        'identity' => 'Identity',
        'access' => 'Access',
        'credentials' => 'Password',
        'provenance' => 'Record',
    ],

    'states' => [
        'active' => 'Active',
        'inactive' => 'Deactivated',
    ],

    'filters' => [
        'is_active' => 'Access',
        'only_active' => 'Active only',
        'only_inactive' => 'Deactivated only',
    ],

    'help' => [
        'password_on_create' => 'Set an initial password and pass it to the person over a channel you trust. The portal sends no e-mail.',
        'password_on_edit' => 'Leave both boxes empty to keep the current password. A stored password is never shown or pre-filled.',
        'email' => 'Stored in lower case and used to sign in.',
        'is_active' => 'Deactivating signs the account out of the panel on its very next request. Accounts are deactivated, never deleted, so their audit history keeps its actor.',
        'own_account' => 'You cannot change your own role or deactivate yourself. Ask another administrator.',
    ],

    'notices' => [
        'provisional_roles' => 'The three roles below are a provisional least-privilege model. Hydromet has not approved the final role matrix or the list of people who should hold each role.',
        'no_deletion' => 'Accounts cannot be deleted here, by design. Deactivate the account instead; its audit history stays readable and keeps its actor.',
        'credentials' => 'Passwords are stored hashed and are never displayed, exported or written to the audit log. There is no password reset e-mail: SMTP is not configured.',
    ],

    'errors' => [
        'not_permitted' => 'Only an active administrator may manage user accounts.',
        'last_active_administrator' => 'This is the last active administrator. Give another account the administrator role first, or the portal would lock everyone out.',
        'self_deactivation' => 'You cannot deactivate your own account. Ask another administrator to do it.',
        'self_role_change' => 'You cannot change your own role. Ask another administrator to do it.',
        'email_taken' => 'Another account already uses this e-mail address.',
        'incomplete_account' => 'A new account needs a name, an e-mail address and a password.',
        'account_missing' => 'This account no longer exists.',
        'installation_not_empty' => 'This installation already has user accounts. The first administrator can only be created while the user table is empty.',
        'session_ended' => 'Your access changed, so this session ended. Please sign in again.',
    ],

    'bootstrap' => [
        'intro' => 'Creating the first administrator. This is possible only while no user account exists.',
        'not_empty' => 'This installation already has user accounts.',
        'not_empty_hint' => 'The first administrator is created once, on an empty user table. Create every further account in the panel, under Identity → User accounts.',
        'created' => 'Administrator :email created and activated.',
        'next_steps' => 'Sign in at /admin and create a second administrator: a sole administrator can no longer be deactivated or demoted by anyone, including themselves.',
    ],
];
