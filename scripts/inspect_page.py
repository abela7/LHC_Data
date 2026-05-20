import sys
import pdfplumber

pdf_path = r"c:\xampp\htdocs\LHC_Data\public\SHERRYS CATALOGUE 2026 JAN .pdf"

try:
    with pdfplumber.open(pdf_path) as pdf:
        page_index = 100 # Page 101 usually means index 100
        if len(pdf.pages) > page_index:
            page = pdf.pages[page_index]
            print(f"--- EXTRACT TEXT (PAGE {page_index+1}) ---")
            print(page.extract_text())
            
            print("\n--- First 40 Words ---")
            words = page.extract_words()
            for i, word in enumerate(words[:40]):
                print(f"{word['text']} @ (x={word['x0']:.1f}, top={word['top']:.1f})")
        else:
            print("Page 101 does not exist.")
except Exception as e:
    print(f"Error: {e}")
