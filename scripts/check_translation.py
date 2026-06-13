import re
import sys
import io
import os
import glob

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

script_dir = os.path.dirname(os.path.abspath(__file__))
lang_dir = os.path.join(script_dir, '..', 'languages')
po_files = glob.glob(os.path.join(lang_dir, '*.po'))

if not po_files:
    print(f"No .po files found in {lang_dir}")
    sys.exit(0)

jp_char = r"[\u3040-\u309f\u30a0-\u30ff\u4e00-\u9faf\u30fc]"
eng_char = r"[a-zA-Z]"

for po_file_path in po_files:
    print(f"\n--- Checking {os.path.basename(po_file_path)} ---")
    with open(po_file_path, "r", encoding="utf-8") as f:
        po_content = f.read()

    blocks = re.split(r'\n\n+', po_content)
    translations = []

    for block in blocks:
        lines = [line.strip() for line in block.split('\n') if line.strip() and not line.strip().startswith('#')]
        if not lines:
            continue
        
        msgid = ""
        msgstr = ""
        
        current_field = None
        for line in lines:
            if line.startswith('msgid'):
                current_field = 'msgid'
                content = re.findall(r'"(.*)"', line)
                if content:
                    msgid = content[0]
            elif line.startswith('msgstr'):
                current_field = 'msgstr'
                content = re.findall(r'"(.*)"', line)
                if content:
                    msgstr = content[0]
            elif line.startswith('"') and line.endswith('"'):
                content = line[1:-1]
                if current_field == 'msgid':
                    msgid += content
                elif current_field == 'msgstr':
                    msgstr += content
                    
        if msgid and msgstr:
            translations.append((msgid, msgstr))

    for msgid, msgstr in translations:
        val = msgstr.replace('\\"', '"').replace('\\n', '\n')
        
        # 1. Disallowed terms
        for term in ["下さい", "全て"]:
            if term in val:
                print(f"  Disallowed term '{term}': msgid='{msgid}' msgstr='{val}'")
                
        # 2. Full-width alphanumeric/punctuation that should be half-width
        fullwidth = re.findall(r"[？！ａ-ｚＡ-Ｚ０-９]", val)
        if fullwidth:
            print(f"  Full-width chars {fullwidth}: msgid='{msgid}' msgstr='{val}'")
            
        # 3. Numbers with spaces
        num_sp = re.findall(r"(?:\s\d+|\d+\s)", val)
        num_sp = [x for x in num_sp if ' ' in x]
        if num_sp:
            print(f"  Spaces around numbers {num_sp}: msgid='{msgid}' msgstr='{val}'")
            
        # 4. English letters spacing
        missing_sp_1 = re.findall(f"({jp_char})({eng_char})", val)
        if missing_sp_1:
            print(f"  Missing space before English {missing_sp_1}: msgid='{msgid}' msgstr='{val}'")
            
        missing_sp_2 = re.findall(f"({eng_char})({jp_char})", val)
        if missing_sp_2:
            print(f"  Missing space after English {missing_sp_2}: msgid='{msgid}' msgstr='{val}'")
            
        # 5. Parentheses spacing
        space_inside = re.findall(r"\(\s|\s\)", val)
        if space_inside:
            print(f"  Spaces inside parentheses: msgid='{msgid}' msgstr='{val}'")
            
        missing_paren_sp_1 = re.findall(r"([^ \n「『（、。])\(", val)
        missing_paren_sp_1 = [x for x in missing_paren_sp_1 if x not in ['(', ')']]
        if missing_paren_sp_1:
            print(f"  Missing space before '(': msgid='{msgid}' msgstr='{val}'")
            
        missing_paren_sp_2 = re.findall(r"\)([^ \n」、。』])", val)
        missing_paren_sp_2 = [x for x in missing_paren_sp_2 if x not in ['(', ')']]
        if missing_paren_sp_2:
            print(f"  Missing space after ')': msgid='{msgid}' msgstr='{val}'")
