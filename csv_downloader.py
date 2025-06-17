# Step 1: Import required modules
import imaplib
import email
from email.header import decode_header
import re
import os
import requests
from datetime import datetime

# Step 2: Define Gmail credentials
username = "kai.ying@mnctechsolutions.com"
app_password = "fvin lerm bdfs tskj"
if not app_password:
    raise ValueError("GMAIL_APP_PASSWORD environment variable not set")

# Step 3: Function to retrieve the download link for the exported CSV from Gmail
def get_export_download_link():
    try:
        # Connect to Gmail IMAP server
        mail = imaplib.IMAP4_SSL("imap.gmail.com")
        mail.login(username, app_password)
        mail.select("inbox")

        # Search for emails from noreply@lc.salesjourney360.com with subject "Transaction List"
        status, data = mail.search(None, '(FROM "noreply@lc.salesjourney360.com") (SUBJECT "Transaction List")')
        if status != "OK":
            print("No export emails found from noreply@lc.salesjourney360.com with subject 'Transaction List'.")
            return None

        # Get the latest email
        email_ids = data[0].split()
        if not email_ids:  # Check if any emails were found
            print("No matching emails found.")
            return None
        latest_email_id = email_ids[-1]  # Get the most recent email

        # Fetch the email
        status, msg_data = mail.fetch(latest_email_id, "(RFC822)")
        if status != "OK":
            print("Failed to fetch export email.")
            return None

        # Parse the email
        msg = email.message_from_bytes(msg_data[0][1])
        subject, encoding = decode_header(msg["subject"])[0]
        if isinstance(subject, bytes):
            subject = subject.decode(encoding if encoding else "utf-8")

        # Confirm the email subject
        if "transaction list" not in subject.lower():
            print("Latest email is not the export email. Subject:", subject)
            return None

        # Get the email body
        if msg.is_multipart():
            for part in msg.walk():
                if part.get_content_type() == "text/plain":
                    body = part.get_payload(decode=True).decode()
                    break
        else:
            body = msg.get_payload(decode=True).decode()

        # Extract the download link (the one after "or click the link below")
        link_match = re.search(r"or click the link below:.*?\n.*?\n\[?(https://[^\]\s]+)\]?", body, re.DOTALL)
        if link_match:
            download_url = link_match.group(1)
            print(f"Retrieved download URL: {download_url}")
            return download_url
        else:
            print("Could not find download link in the email. Body:", body)
            return None

    except Exception as e:
        print(f"Error retrieving download link: {e}")
        return None
    finally:
        mail.logout()

# Step 4: Function to download the CSV file and save it to Desktop/web_scrap
def download_csv(download_url):
    try:
        # Define the save directory
        save_dir = r"C:\Users\MNC Tech\OneDrive\Desktop\web_scrap\csv_files"
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
            print(f"CSV file downloaded and saved to: {save_path}")
        else:
            print(f"Failed to download CSV. Status code: {response.status_code}")
    except Exception as e:
        print(f"Error downloading CSV: {e}")

# Step 5: Main execution
try:
    # Retrieve the download link from Gmail
    print("Retrieving download link from Gmail...")
    download_url = get_export_download_link()
    if not download_url:
        raise Exception("Failed to retrieve download link")

    # Download the CSV file
    download_csv(download_url)

except Exception as e:
    print(f"An error occurred: {e}")