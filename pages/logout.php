<?php
logout();
session_start();
flash('success', 'You have been signed out.');
redirect('login');
