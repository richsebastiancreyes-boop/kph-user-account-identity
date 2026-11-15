<?php
// Two extra exceptions. The API turns them into status codes:
//   InvalidArgumentException -> 400   (bad data, broken rule)
//   AuthenticationException  -> 401   (wrong login details)
//   ForbiddenException       -> 403   (known user, but not allowed)
//   OutOfBoundsException     -> 404   (record not found)

class AuthenticationException extends RuntimeException
{
}

class ForbiddenException extends RuntimeException
{
}
