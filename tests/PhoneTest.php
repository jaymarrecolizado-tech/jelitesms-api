<?php

declare(strict_types=1);

use Jelite\Phone;

section('Phone E.164');

same('+639171234567', Phone::toE164('09171234567'), 'local 0-prefix → +63');
same('+639171234567', Phone::toE164('9171234567'), 'bare subscriber → +63');
same('+639171234567', Phone::toE164('+639171234567'), 'already E.164 kept');
same('+639171234567', Phone::toE164('639171234567'), '63-prefix without plus');
same('+639171234567', Phone::toE164('+63 917 123 4567'), 'spaces/dashes stripped');
same('+14155551234', Phone::toE164('+14155551234'), 'other country with + kept');
check(Phone::toE164('') === null, 'empty → null');
check(Phone::toE164('abc') === null, 'letters → null');
check(Phone::toE164('+') === null, 'plus only → null');
check(Phone::toE164('12345') === null, 'too short rejected');
