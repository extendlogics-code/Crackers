<?php
// send_mail.php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = strip_tags(trim($_POST['name']));
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $phone = strip_tags(trim($_POST['phone']));
    $message = strip_tags(trim($_POST['message']));

    if (empty($name) || empty($email) || empty($message)) {
        echo json_encode(['message' => 'Please fill in all required fields.']);
        exit;
    }

    $to = "priya6pr@gmail.com"; // replace with your email
    $subject = "New Contact Form Submission";
    $body = "Name: $name\nEmail: $email\nPhone: $phone\nMessage:\n$message";
    $headers = "From: $email\r\nReply-To: $email\r\n";

    if (mail($to, $subject, $body, $headers)) {
        echo json_encode(['message' => 'Thanks! We will get back to you.']);
    } else {
        echo json_encode(['message' => 'Sorry, your message could not be sent.']);
    }
    exit;
}
?>
