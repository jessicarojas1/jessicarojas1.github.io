<?php
declare(strict_types=1);

class ProfileController {

    public function notifications(): void {
        require AEGIS_ROOT . '/views/profile/notifications.php';
    }
}
