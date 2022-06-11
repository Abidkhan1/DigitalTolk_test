# DigitalTolk_test

BookingController.php

1 - Here code is written vey nicely, simple logic and big logics written in different file, this approach is helpful in Reading, understanding and changing code easily. Code reusability with functions written in different file.

BookingRepository.php

1- Lots of functions in single BookingRepository.php file, it is not a good idea to make all logics in same file, this cause hard to understand, update and remove bugs.
For overcoming this Issue I've Created traits, where similar type functions stored to easily understand, update, and remove bugs.

2- Repeating Response[message],[status], and [field_name] in store() {BookingRepository.php}, I created new function setResponse() for removing repeating lines of code.

3- alerts() function is written in "BookingRepository.php" but not being used anywhere, for me I'll remove this function if it is not being used in any-other hidden file.
 
