<?php

test('the home page redirects to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});
