import logging
import os
import re
import time
import imaplib
import email
from email.header import decode_header
from email.utils import parsedate_to_datetime
import requests
from datetime import datetime, timedelta
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from webdriver_manager.chrome import ChromeDriverManager
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.common.keys import Keys
from selenium.common.exceptions import TimeoutException

# Configure logging to output to both file and terminal
logger = logging.getLogger()
logger.setLevel(logging.INFO)

# File handler for scraper_log.txt
file_handler = logging.FileHandler(r"C:\xampp\htdocs\credit-tallying\scraper_log.txt")
file_handler.setFormatter(logging.Formatter("%(asctime)s - %(levelname)s - %(message)s"))
logger.addHandler(file_handler)

# Stream handler for terminal output
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter("%(asctime)s - %(levelname)s - %(message)s"))
logger.addHandler(console_handler)

# Step 1: Define GHL URLs and credentials
website_url = "https://app.salesjourney360.com"
billing_url = "https://app.salesjourney360.com/settings/billing?tab=wallet_transactions"

# Get credentials
username = "kai.ying@mnctechsolutions.com"
password = "20021117Cky@"
app_password = "fvin lerm bdfs tskj"

if not all([username, password, app_password]):
    raise ValueError("Required environment variables not set (USERNAME, PASSWORD, GMAIL_APP_PASSWORD)")

# Step 2: Paths for CSV and last export date
csv_dir = r"C:\xampp\htdocs\credit-tallying\csv_files"
last_export_date_file = r"C:\xampp\htdocs\credit-tallying\last_export_date.txt"

# Step 3: Function to get the start date from stored file
def get_start_date():
    try:
        # Check if last_export_date.txt exists
        if os.path.exists(last_export_date_file):
            with open(last_export_date_file, "r") as f:
                date_str = f.read().strip()
                start_date = datetime.strptime(date_str, "%Y-%m-%d %H:%M:%S")
                logging.info(f"Retrieved start date from file: {start_date}")
                return start_date

        # If no stored date, use default (6 months ago)
        logging.warning("last_export_date.txt not found, using 6 months ago as start date.")
        return datetime.now() - timedelta(days=180)

    except Exception as e:
        logging.error(f"Error getting start date: {e}")
        return datetime.now() - timedelta(days=180)

# Step 4: Function to save the end date
def save_end_date(end_date):
    try:
        with open(last_export_date_file, "w") as f:
            f.write(end_date.strftime("%Y-%m-%d %H:%M:%S"))
        logging.info(f"Saved end date to file: {end_date}")
    except Exception as e:
        logging.error(f"Error saving end date: {e}")

# Step 5: Set up Chrome options with recommended settings
chrome_options = Options()
chrome_options.add_argument("--no-sandbox")
chrome_options.add_argument("--disable-dev-shm-usage")
chrome_options.add_argument("--disable-gpu")
chrome_options.add_argument("--disable-extensions")
chrome_options.add_argument("--disable-popup-blocking")
chrome_options.add_argument("--start-maximized")
chrome_options.add_experimental_option("excludeSwitches", ["enable-logging"])

# Step 6: Set up Selenium WebDriver
service = Service(ChromeDriverManager().install())
driver = webdriver.Chrome(service=service, options=chrome_options)

# Step 7: Function to retrieve OTP from Gmail using IMAP
def get_otp_from_gmail():
    try:
        # Connect to Gmail IMAP server
        mail = imaplib.IMAP4_SSL("imap.gmail.com")
        mail.login(username, app_password)
        mail.select("inbox")

        # Search for emails from noreply@lc.salesjourney360.com
        status, data = mail.search(None, '(FROM "noreply@lc.salesjourney360.com")')
        if status != "OK":
            logging.error("No emails found from noreply@lc.salesjourney360.com.")
            return None

        # Get the latest email
        email_ids = data[0].split()
        latest_email_id = email_ids[-1]  # Get the most recent email

        # Fetch the email
        status, msg_data = mail.fetch(latest_email_id, "(RFC822)")
        if status != "OK":
            logging.error("Failed to fetch email.")
            return None

        # Parse the email
        msg = email.message_from_bytes(msg_data[0][1])
        subject, encoding = decode_header(msg["subject"])[0]
        if isinstance(subject, bytes):
            subject = subject.decode(encoding if encoding else "utf-8")

        # Check if the email is the OTP email
        if "login security code" not in subject.lower():
            logging.error(f"Latest email is not an OTP email. Subject: {subject}")
            return None

        # Get the email body
        if msg.is_multipart():
            for part in msg.walk():
                if part.get_content_type() == "text/plain":
                    body = part.get_payload(decode=True).decode()
                    break
        else:
            body = msg.get_payload(decode=True).decode()

        # Extract the OTP (6-digit code after "Your login security code:")
        otp_match = re.search(r"Your login security code: (\d{6})", body)
        if otp_match:
            otp = otp_match.group(1)
            logging.info(f"Retrieved OTP: {otp}")
            return otp
        else:
            logging.error(f"Could not find OTP in the email. Body: {body}")
            return None

    except Exception as e:
        logging.error(f"Error retrieving OTP: {e}")
        return None
    finally:
        mail.logout()

# Step 8: Function to retrieve the download link for the exported CSV from Gmail
def get_export_download_link(export_start_time):
    try:
        # Make export_start_time timezone-naive if it has timezone info
        if export_start_time.tzinfo is not None:
            export_start_time = export_start_time.replace(tzinfo=None)

        # Connect to Gmail IMAP server
        mail = imaplib.IMAP4_SSL("imap.gmail.com")
        mail.login(username, app_password)
        mail.select("inbox")
        
        logging.info("Connected to Gmail and selected inbox")

        # Search for emails with broader criteria
        search_criteria = '(OR (FROM "noreply@lc.salesjourney360.com") (FROM "noreply@salesjourney360.com") (SUBJECT "Transaction List"))'
        status, data = mail.search(None, search_criteria)
        
        if status != "OK":
            logging.error("Failed to search for emails")
            return None

        email_ids = data[0].split()
        if not email_ids:
            logging.error("No matching emails found")
            return None

        logging.info(f"Found {len(email_ids)} matching emails")

        # Get the latest email ID
        latest_email_id = email_ids[-1]
        
        # Fetch the email
        status, msg_data = mail.fetch(latest_email_id, "(RFC822)")
        if status != "OK":
            logging.error("Failed to fetch email")
            return None

        # Parse the email
        msg = email.message_from_bytes(msg_data[0][1])
        subject = msg["subject"]
        date = parsedate_to_datetime(msg["date"])
        
        logging.info(f"Latest email - Subject: {subject}, Date: {date}")

        # Get the email body
        body = None
        if msg.is_multipart():
            for part in msg.walk():
                if part.get_content_type() == "text/html":
                    body = part.get_payload(decode=True).decode()
                    break
                elif part.get_content_type() == "text/plain" and not body:
                    body = part.get_payload(decode=True).decode()
        else:
            body = msg.get_payload(decode=True).decode()

        if not body:
            logging.error("Could not find email body")
            return None

        # Log the first 500 characters of the body for debugging
        logging.info(f"Email body preview: {body[:500]}")

        # Look for download link with more flexible patterns
        link_patterns = [
            r'href=["\']?(https://[^"\'\s>]+salesjourney360[^"\'\s>]+)["\']?',
            r'\[(https://[^]\s]+salesjourney360[^]\s]+)\]',
            r'(https://[^"\'\s<>]+salesjourney360[^"\'\s<>]+)'
        ]

        for pattern in link_patterns:
            link_match = re.search(pattern, body, re.IGNORECASE)
            if link_match:
                download_url = link_match.group(1)
                logging.info(f"Found download URL: {download_url}")
                return download_url

        logging.error("Could not find download link in the email")
        return None

    except Exception as e:
        logging.error(f"Error retrieving download link: {e}")
        return None
    finally:
        try:
            mail.logout()
        except:
            pass

# Step 9: Function to download the CSV file and save it to csv_files
def download_csv(download_url):
    try:
        # Define the save directory
        save_dir = csv_dir
        if not os.path.exists(save_dir):
            os.makedirs(save_dir)

        # Create a timestamped filename
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        filename = f"transaction_list_{timestamp}.csv"
        save_path = os.path.join(save_dir, filename)

        # Download the file
        response = requests.get(download_url, stream=True)
        if response.status_code == 200:
            with open(save_path, "wb") as f:
                for chunk in response.iter_content(chunk_size=8192):
                    if chunk:
                        f.write(chunk)
            logging.info(f"CSV file downloaded and saved to: {save_path}")
            
            # Create a flag file to indicate new data is available
            flag_file = os.path.join(save_dir, ".new_data")
            with open(flag_file, "w") as f:
                f.write(filename)
            logging.info("Created flag file to notify CSV processor")
            
            return save_path
        else:
            logging.error(f"Failed to download CSV. Status code: {response.status_code}")
            return None
    except Exception as e:
        logging.error(f"Error downloading CSV: {e}")
        return None

try:
    # Step 10: Navigate to the GHL website
    driver.get(website_url)
    logging.info(f"Successfully opened the website: {website_url}")

    # Step 11: Handle potential overlays (e.g., cookie popup)
    try:
        cookie_button = WebDriverWait(driver, 5).until(
            EC.element_to_be_clickable((By.XPATH, "//button[contains(text(), 'Accept')]"))
        )
        cookie_button.click()
        logging.info("Dismissed cookie popup")
    except:
        logging.info("No cookie popup found")

    # Step 12: Wait for the email field to be clickable and handle potential overlays
    try:
        # Wait for any loading overlay to disappear
        WebDriverWait(driver, 10).until_not(
            EC.presence_of_element_located((By.CLASS_NAME, "loading-overlay"))
        )
        
        # Wait for the email field
        email_field = WebDriverWait(driver, 15).until(
            EC.element_to_be_clickable((By.ID, "email"))
        )

        # Take a screenshot for debugging
        logging.info("Saved screenshot before login attempt")

        # Scroll the email field into view and click it to ensure it's interactable
        driver.execute_script("arguments[0].scrollIntoView(true);", email_field)
        time.sleep(1)  # Short pause to let the scroll complete
        ActionChains(driver).move_to_element(email_field).click().perform()
        time.sleep(1)  # Short pause after clicking

        # Step 13: Enter the email
        email_field.clear()  # Clear any existing text
        email_field.send_keys(username)
        logging.info("Entered email")
        time.sleep(1)  # Short pause after entering email

        # Step 14: Enter the password
        password_field = WebDriverWait(driver, 10).until(
            EC.element_to_be_clickable((By.NAME, "password"))
        )
        password_field.clear()  # Clear any existing text
        password_field.send_keys(password)
        logging.info("Entered password")
        time.sleep(1)  # Short pause after entering password

        # Take another screenshot before clicking sign in
        logging.info("Saved screenshot before clicking sign in")

        # Step 15: Click the "Sign in" button
        login_button = WebDriverWait(driver, 10).until(
            EC.element_to_be_clickable((By.XPATH, "//button[contains(text(), 'Sign in')]"))
        )
        driver.execute_script("arguments[0].scrollIntoView(true);", login_button)
        time.sleep(1)  # Short pause before clicking
        login_button.click()
        logging.info("Clicked 'Sign in' button")

        # Wait for either the verification page or an error message
        try:
            error_message = WebDriverWait(driver, 5).until(
                EC.presence_of_element_located((By.CLASS_NAME, "error-message"))
            )
            logging.error(f"Login error: {error_message.text}")
            raise Exception(f"Login failed: {error_message.text}")
        except TimeoutException:
            logging.info("No error message found, proceeding with verification")

        # Take a screenshot after login attempt
        logging.info("Saved screenshot after login attempt")

    except Exception as e:
        logging.error(f"Error during login process: {e}")
        raise

    # Step 16: Wait for the verification page and click "Send Security Code"
    send_code_button = WebDriverWait(driver, 10).until(
        EC.element_to_be_clickable((By.XPATH, "//button[contains(text(), 'Send Security Code')]"))
    )
    send_code_button.click()
    logging.info("Clicked 'Send Security Code' button")

    # Step 17: Wait for OTP prompt
    time.sleep(5)  # Wait for the OTP page to load
    otp_field = WebDriverWait(driver, 10).until(
        EC.element_to_be_clickable((By.NAME, "otp"))
    )

    # Step 18: Retrieve OTP from Gmail
    logging.info("Retrieving OTP from Gmail...")
    otp = get_otp_from_gmail()
    if not otp:
        raise Exception("Failed to retrieve OTP")

    # Step 19: Enter the OTP
    otp_fields = WebDriverWait(driver, 10).until(
        EC.presence_of_all_elements_located((By.CLASS_NAME, "otp-input"))
    )
    if len(otp_fields) != 6:
        raise Exception(f"Expected 6 OTP input fields, found {len(otp_fields)}")

    for i, digit in enumerate(otp):
        driver.execute_script("arguments[0].scrollIntoView(true);", otp_fields[i])
        otp_fields[i].click()
        otp_fields[i].send_keys(digit)
    logging.info("Submitted OTP")

    # Step 20: Wait and verify login
    time.sleep(10)  # Wait to ensure page loads
    page_title = driver.title
    logging.info(f"Page title after login: {page_title}")

    # Step 21: Navigate to the billing page
    driver.get(billing_url)
    logging.info(f"Navigated to billing page: {billing_url}")
    time.sleep(5)  # Wait for the billing page to load
    billing_page_title = driver.title
    logging.info(f"Billing page title: {billing_page_title}")

    # Step 22: Select the "Detailed Transactions" tab
    detailed_tab = WebDriverWait(driver, 10).until(
        EC.element_to_be_clickable((By.XPATH, "//div[@data-name='detailed']"))
    )
    driver.execute_script("arguments[0].scrollIntoView(true);", detailed_tab)
    detailed_tab.click()
    logging.info("Clicked 'Detailed Transactions' tab")
    time.sleep(5)  # Wait for tab content to load
    logging.info(f"Page title after selecting tab: {driver.title}")

    # Step 23: Get start and end dates
    start_date = get_start_date()
    end_date = datetime.now()
    start_date_time_str = start_date.strftime("%Y-%m-%d %H:%M:%S")  # e.g., 2025-04-16 10:16:18
    end_date_time_str = end_date.strftime("%Y-%m-%d %H:%M:%S")      # e.g., 2025-05-09 15:24:25
    logging.info(f"Using start date-time: {start_date_time_str}, end date-time: {end_date_time_str}")

    # Step 24: Interact with the Advanced Filters button before date-time picker
    try:
        # Click the Advanced Filters button to reveal date picker
        advanced_filters_button = WebDriverWait(driver, 10).until(
            EC.element_to_be_clickable((By.ID, "AdvancedFiltersButton"))
        )
        logging.info(f"Advanced Filters button found: displayed={advanced_filters_button.is_displayed()}, enabled={advanced_filters_button.is_enabled()}")
        driver.execute_script("arguments[0].scrollIntoView(true);", advanced_filters_button)
        ActionChains(driver).move_to_element(advanced_filters_button).click().perform()
        logging.info("Clicked Advanced Filters button")
        time.sleep(2)  # Wait for any UI updates after button click

        # Helper function to clear a field
        def clear_field(field, field_name):
            initial_value = field.get_attribute("value")
            logging.info(f"{field_name} initial value: {initial_value}")
            # Try JavaScript clear first
            for attempt in range(3):
                driver.execute_script("arguments[0].value = '';", field)
                time.sleep(0.5)
                cleared_value = field.get_attribute("value")
                if not cleared_value:
                    logging.info(f"JavaScript clear succeeded for {field_name}")
                    return
                logging.warning(f"JavaScript clear attempt {attempt+1} failed for {field_name}, value remains: {cleared_value}")
            # Try Selenium clear
            field.clear()
            time.sleep(0.5)
            cleared_value = field.get_attribute("value")
            if cleared_value:
                logging.warning(f"Selenium clear() failed for {field_name}, value remains: {cleared_value}")
                # Try key press clear
                field.send_keys(Keys.CONTROL + "a")
                field.send_keys(Keys.DELETE)
                time.sleep(0.5)
                cleared_value = field.get_attribute("value")
                if cleared_value:
                    logging.error(f"All clear attempts failed for {field_name}, value remains: {cleared_value}")
                else:
                    logging.info(f"Key press clear succeeded for {field_name}")
            else:
                logging.info(f"Selenium clear succeeded for {field_name}")
            logging.info(f"{field_name} after clear: {cleared_value or 'empty'}")

        # Start Date and Time
        start_date_input = WebDriverWait(driver, 10).until(
            EC.element_to_be_clickable((By.XPATH, "//input[@class='n-input__input-el' and @placeholder='Start Date and Time']"))
        )
        logging.info(f"Start date-time input found: displayed={start_date_input.is_displayed()}, enabled={start_date_input.is_enabled()}")
        field_attrs = {
            "value": start_date_input.get_attribute("value"),
            "readonly": start_date_input.get_attribute("readonly"),
            "disabled": start_date_input.get_attribute("disabled"),
            "class": start_date_input.get_attribute("class")
        }
        logging.info(f"Start date-time input attributes: {field_attrs}")
        driver.execute_script("arguments[0].scrollIntoView(true);", start_date_input)
        ActionChains(driver).move_to_element(start_date_input).click().perform()
        logging.info("Clicked start date-time input")

        clear_field(start_date_input, "Start date-time input")
        start_date_input.send_keys(start_date_time_str)
        time.sleep(0.5)
        if start_date_input.get_attribute("value") != start_date_time_str:
            logging.warning("send_keys() failed for start date-time, using JavaScript")
            driver.execute_script("arguments[0].value = arguments[1];", start_date_input, start_date_time_str)
        actual_value = start_date_input.get_attribute("value")
        if actual_value != start_date_time_str:
            raise Exception(f"Start date-time not updated: expected {start_date_time_str}, got {actual_value}")
        logging.info(f"Verified start date-time: {start_date_time_str}")
        time.sleep(5)  # Wait 5s for observation

        # Click "Confirm" for start date-time (if present)
        try:
            confirm_button = WebDriverWait(driver, 5).until(
                EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'Confirm')]"))
            )
            driver.execute_script("arguments[0].scrollIntoView(true);", confirm_button)
            confirm_button.click()
            logging.info("Clicked 'Confirm' button for start date-time")
        except TimeoutException:
            logging.info("No 'Confirm' button found for start date-time, proceeding")

        # End Date and Time
        end_date_input = WebDriverWait(driver, 10).until(
            EC.element_to_be_clickable((By.XPATH, "//input[@class='n-input__input-el' and @placeholder='End Date and Time']"))
        )
        logging.info(f"End date-time input found: displayed={end_date_input.is_displayed()}, enabled={end_date_input.is_enabled()}")
        field_attrs = {
            "value": end_date_input.get_attribute("value"),
            "readonly": end_date_input.get_attribute("readonly"),
            "disabled": end_date_input.get_attribute("disabled"),
            "class": end_date_input.get_attribute("class")
        }
        logging.info(f"End date-time input attributes: {field_attrs}")
        driver.execute_script("arguments[0].scrollIntoView(true);", end_date_input)
        ActionChains(driver).move_to_element(end_date_input).click().perform()
        logging.info("Clicked end date-time input")

        clear_field(end_date_input, "End date-time input")
        end_date_input.send_keys(end_date_time_str)
        time.sleep(0.5)
        if end_date_input.get_attribute("value") != end_date_time_str:
            logging.warning("send_keys() failed for end date-time, using JavaScript")
            driver.execute_script("arguments[0].value = arguments[1];", end_date_input, end_date_time_str)
        actual_value = end_date_input.get_attribute("value")
        if actual_value != end_date_time_str:
            raise Exception(f"End date-time not updated: expected {end_date_time_str}, got {actual_value}")
        logging.info(f"Verified end date-time: {end_date_time_str}")
        time.sleep(5)  # Wait 5s for observation

        # Click "Confirm" for end date-time (if present)
        try:
            confirm_button = WebDriverWait(driver, 5).until(
                EC.element_to_be_clickable((By.XPATH, "//*[contains(text(), 'Confirm')]"))
            )
            driver.execute_script("arguments[0].scrollIntoView(true);", confirm_button)
            confirm_button.click()
            logging.info("Clicked 'Confirm' button for end date-time")
        except TimeoutException:
            logging.info("No 'Confirm' button found for end date-time, proceeding")

        # add apply
        try:
            apply_button = WebDriverWait(driver, 10).until(
                EC.element_to_be_clickable((By.XPATH, "//button[@class='n-button n-button--primary-type n-button--medium-type' and .//span[text()='Apply']]"))
            )
            logging.info(f"Apply button found: displayed={apply_button.is_displayed()}, enabled={apply_button.is_enabled()}")
            driver.execute_script("arguments[0].scrollIntoView(true);", apply_button)
            ActionChains(driver).move_to_element(apply_button).click().perform()
            logging.info("Clicked 'Apply' button")
            time.sleep(2)  # Wait for any UI updates after apply
        except TimeoutException:
            logging.error("Apply button not found or not clickable")
            raise Exception("Failed to locate or click the Apply button")

        time.sleep(60)

    except Exception as e:
        logging.error(f"Failed to interact with date-time picker: {e}")
        # Fallback: Set date-times directly without picker
        logging.info("Attempting direct date-time input without picker")
        try:
            start_date_input = driver.find_element(By.XPATH, "//input[@class='n-input__input-el' and @placeholder='Start Date and Time']")
            driver.execute_script("arguments[0].value = '';", start_date_input)
            driver.execute_script("arguments[0].value = arguments[1];", start_date_input, start_date_time_str)
            actual_value = start_date_input.get_attribute("value")
            if actual_value != start_date_time_str:
                logging.error(f"Direct input failed for start date-time: expected {start_date_time_str}, got {actual_value}")
            else:
                logging.info(f"Set start date-time directly: {start_date_time_str}")

            end_date_input = driver.find_element(By.XPATH, "//input[@class='n-input__input-el' and @placeholder='End Date and Time']")
            driver.execute_script("arguments[0].value = '';", end_date_input)
            driver.execute_script("arguments[0].value = arguments[1];", end_date_input, end_date_time_str)
            actual_value = end_date_input.get_attribute("value")
            if actual_value != end_date_time_str:
                logging.error(f"Direct input failed for end date-time: expected {end_date_time_str}, got {actual_value}")
            else:
                logging.info(f"Set end date-time directly: {end_date_time_str}")
        except Exception as fallback_e:
            logging.error(f"Fallback direct input failed: {fallback_e}")
            raise

    # Step 25: Wait 5 seconds before clicking "Export"
    time.sleep(15)
    export_button = WebDriverWait(driver, 10).until(
        EC.element_to_be_clickable((By.ID, "TransactionExportButton"))
    )
    driver.execute_script("arguments[0].scrollIntoView(true);", export_button)
    export_button.click()
    logging.info("Clicked 'Export' button")
    time.sleep(5)  # Wait for modal to fully load

    # Step 26: Enter the user email in the export modal
    selection_div = WebDriverWait(driver, 5).until(
        EC.element_to_be_clickable((By.ID, "SearchUserSelect"))
    )
    driver.execute_script("arguments[0].scrollIntoView(true);", selection_div)
    ActionChains(driver).move_to_element(selection_div).click().perform()
    logging.info("Clicked the selection div to activate input")

    ActionChains(driver).send_keys(username).pause(7).send_keys(Keys.ENTER).perform()
    logging.info(f"Entered and confirmed email in modal: {username}")
    time.sleep(1)  # Wait for the modal to process the input

    # Step 27: Click the "Export CSV" button in the modal
    export_csv_button = WebDriverWait(driver, 10).until(
        EC.element_to_be_clickable((By.ID, "ExportTransactionsFooter-btn-positive-action"))
    )
    driver.execute_script("arguments[0].scrollIntoView(true);", export_csv_button)
    driver.execute_script("arguments[0].click();", export_csv_button)
    logging.info("Clicked 'Export CSV' button")
    time.sleep(3)  # Wait for the export action to complete
    logging.info(f"Page title after exporting: {driver.title}")

    # Step 28: Save the end date for the next run
    save_end_date(end_date)

    # Step 29: Wait for the export email to arrive
    logging.info("Waiting for export email to arrive...")
    export_start_time = datetime.now()  # Record time when export is triggered
    max_wait = 600  # Maximum wait of 10 minutes
    wait_interval = 30  # Check every 30 seconds
    elapsed = 0
    time.sleep(10)  # Initial wait to allow email to arrive
    download_url = None
    while elapsed < max_wait:
        download_url = get_export_download_link(export_start_time)
        if download_url:
            break
        logging.info(f"Export email not found yet, waiting {wait_interval} seconds...")
        time.sleep(wait_interval)
        elapsed += wait_interval
    if not download_url:
        raise Exception("Export email did not arrive within 10 minutes")

    # Step 30: Download the CSV file and process it
    csv_path = download_csv(download_url)
    if csv_path:
        logging.info(f"Successfully downloaded CSV to: {csv_path}")
    else:
        raise Exception("Failed to download CSV file")

except Exception as e:
    logging.error(f"An error occurred: {e}")
    print(f"An error occurred: {e}")

finally:
    # Step 31: Keep browser open for debugging
    logging.info("Browser remains open for manual inspection")
    print("Browser remains open for manual inspection")