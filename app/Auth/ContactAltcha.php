<?php

namespace App\Auth;

/**
 * The contact form's ALTCHA (/contact/altcha): same proof of work, its own keys, so a proof solved
 * for the sign-up or the sandbox is refused by the contact form and the other way round.
 */
class ContactAltcha extends Altcha
{
    protected const SCOPE = 'contact';
}
