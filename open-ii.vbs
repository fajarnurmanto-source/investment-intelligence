Option Explicit
Dim shell, fso, root, ps, command, result
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
root = fso.GetParentFolderName(WScript.ScriptFullName)
ps = shell.ExpandEnvironmentStrings("%SystemRoot%") & "\System32\WindowsPowerShell\v1.0\powershell.exe"
command = Chr(34) & ps & Chr(34) & " -NoProfile -NonInteractive -WindowStyle Hidden -File " & Chr(34) & root & "\open-ii.ps1" & Chr(34) & " -Port 8810"
result = shell.Run(command, 0, True)
If result <> 0 Then
    MsgBox "II belum dapat dibuka. Periksa layanan melalui launcher resmi atau hubungi pengelola aplikasi.", vbExclamation, "Investment Intelligence"
End If
