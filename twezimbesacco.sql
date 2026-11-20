-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Nov 12, 2026 at 08:55 AM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `twezimbesacco`
--

-- --------------------------------------------------------

--
-- Table structure for table `account`
--

CREATE TABLE `account` (
  `ACCOUNT_ID` varchar(20) NOT NULL,
  `MEMBER_ID` varchar(20) DEFAULT NULL,
  `ACCOUNT_STATUS` varchar(20) NOT NULL,
  `CREATION_DATE` date NOT NULL,
  `CURRENT_BALANCE` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `account`
--

INSERT INTO `account` (`ACCOUNT_ID`, `MEMBER_ID`, `ACCOUNT_STATUS`, `CREATION_DATE`, `CURRENT_BALANCE`) VALUES
('ACC201', 'MBR101', 'Active', '2026-01-15', 1250000.00),
('ACC451', 'MBR788', 'Active', '2026-11-11', 10000.00);

-- --------------------------------------------------------

--
-- Table structure for table `loan`
--

CREATE TABLE `loan` (
  `LOAN_ID` varchar(20) NOT NULL,
  `ACCOUNT_ID` varchar(20) DEFAULT NULL,
  `LOAN_DATE` date NOT NULL,
  `DUE_DATE` date NOT NULL,
  `STATUS` varchar(20) NOT NULL,
  `PRINCIPAL_AMOUNT` decimal(15,2) NOT NULL,
  `REMAINING_BALANCE` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loan`
--

INSERT INTO `loan` (`LOAN_ID`, `ACCOUNT_ID`, `LOAN_DATE`, `DUE_DATE`, `STATUS`, `PRINCIPAL_AMOUNT`, `REMAINING_BALANCE`) VALUES
('LN617', 'ACC451', '2026-11-11', '2027-05-11', 'Approved', 200000.00, 200000.00);

-- --------------------------------------------------------

--
-- Table structure for table `loan_payment`
--

CREATE TABLE `loan_payment` (
  `PAYMENT_ID` varchar(20) NOT NULL,
  `LOAN_ID` varchar(20) DEFAULT NULL,
  `STAFF_ID` varchar(20) DEFAULT NULL,
  `AMOUNT` decimal(15,2) NOT NULL,
  `PAYMENT_DATE` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `MEMBER_ID` varchar(20) NOT NULL,
  `NAME` varchar(100) NOT NULL,
  `CONTACT` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`MEMBER_ID`, `NAME`, `CONTACT`) VALUES
('MBR101', 'Atwine mark', '0772111222'),
('MBR788', 'migadde', '0756456354');

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `STAFF_ID` varchar(20) NOT NULL,
  `NAME` varchar(100) NOT NULL,
  `CONTACT_ID` varchar(50) DEFAULT NULL,
  `ROLE` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`STAFF_ID`, `NAME`, `CONTACT_ID`, `ROLE`) VALUES
('STF001', 'Ssempala Emmanuel', '0772111222', 'Teller'),
('STF100', 'lemu denis', '0754333444', 'Loan Officer'),
('STF200', 'alituha emma', '0781555666', 'Administrator');

-- --------------------------------------------------------

--
-- Table structure for table `transaction`
--

CREATE TABLE `transaction` (
  `TRANSACTION_ID` varchar(20) NOT NULL,
  `ACCOUNT_ID` varchar(20) DEFAULT NULL,
  `STAFF_ID` varchar(20) DEFAULT NULL,
  `TRANSACTION_TYPE` varchar(10) NOT NULL,
  `AMOUNT` decimal(15,2) NOT NULL,
  `TIME_STUMP` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction`
--

INSERT INTO `transaction` (`TRANSACTION_ID`, `ACCOUNT_ID`, `STAFF_ID`, `TRANSACTION_TYPE`, `AMOUNT`, `TIME_STUMP`) VALUES
('TX1794410272', 'ACC451', 'STF001', 'CR', 10000.00, '2026-11-11 15:17:52');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account`
--
ALTER TABLE `account`
  ADD PRIMARY KEY (`ACCOUNT_ID`),
  ADD KEY `MEMBER_ID` (`MEMBER_ID`);

--
-- Indexes for table `loan`
--
ALTER TABLE `loan`
  ADD PRIMARY KEY (`LOAN_ID`),
  ADD KEY `ACCOUNT_ID` (`ACCOUNT_ID`);

--
-- Indexes for table `loan_payment`
--
ALTER TABLE `loan_payment`
  ADD PRIMARY KEY (`PAYMENT_ID`),
  ADD KEY `LOAN_ID` (`LOAN_ID`),
  ADD KEY `STAFF_ID` (`STAFF_ID`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`MEMBER_ID`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`STAFF_ID`);

--
-- Indexes for table `transaction`
--
ALTER TABLE `transaction`
  ADD PRIMARY KEY (`TRANSACTION_ID`),
  ADD KEY `ACCOUNT_ID` (`ACCOUNT_ID`),
  ADD KEY `STAFF_ID` (`STAFF_ID`);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account`
--
ALTER TABLE `account`
  ADD CONSTRAINT `account_ibfk_1` FOREIGN KEY (`MEMBER_ID`) REFERENCES `members` (`MEMBER_ID`) ON DELETE CASCADE;

--
-- Constraints for table `loan`
--
ALTER TABLE `loan`
  ADD CONSTRAINT `loan_ibfk_1` FOREIGN KEY (`ACCOUNT_ID`) REFERENCES `account` (`ACCOUNT_ID`);

--
-- Constraints for table `loan_payment`
--
ALTER TABLE `loan_payment`
  ADD CONSTRAINT `loan_payment_ibfk_1` FOREIGN KEY (`LOAN_ID`) REFERENCES `loan` (`LOAN_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `loan_payment_ibfk_2` FOREIGN KEY (`STAFF_ID`) REFERENCES `staff` (`STAFF_ID`);

--
-- Constraints for table `transaction`
--
ALTER TABLE `transaction`
  ADD CONSTRAINT `transaction_ibfk_1` FOREIGN KEY (`ACCOUNT_ID`) REFERENCES `account` (`ACCOUNT_ID`),
  ADD CONSTRAINT `transaction_ibfk_2` FOREIGN KEY (`STAFF_ID`) REFERENCES `staff` (`STAFF_ID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
