using System;
using System.Windows.Forms;

namespace OfflineMinecraftLauncher
{
    public partial class SettingsForm : Form
    {
        public SettingsForm()
        {
            InitializeComponent();
        }

        private void SettingsForm_Load(object sender, EventArgs e)
        {
            // Settings'ten oku
            int max = Properties.Settings.Default.MaxRamMb;
            int min = Properties.Settings.Default.MinRamMb;
            
            if (max < numMaxRam.Minimum) max = (int)numMaxRam.Minimum;
            if (min < numMinRam.Minimum) min = (int)numMinRam.Minimum;
            
            numMaxRam.Value = max;
            numMinRam.Value = min;

            txtJavaPath.Text = Properties.Settings.Default.JavaPath;
            txtJvmArgs.Text = Properties.Settings.Default.JvmArguments;
        }

        private void btnBrowseJava_Click(object sender, EventArgs e)
        {
            using (OpenFileDialog ofd = new OpenFileDialog())
            {
                ofd.Filter = "Java Yürütülebilir Dosyası|java.exe;javaw.exe|Tüm Dosyalar|*.*";
                ofd.Title = "Java Yolunu Seçin";
                if (ofd.ShowDialog() == DialogResult.OK)
                {
                    txtJavaPath.Text = ofd.FileName;
                }
            }
        }

        private void btnSave_Click(object sender, EventArgs e)
        {
            Properties.Settings.Default.MaxRamMb = (int)numMaxRam.Value;
            Properties.Settings.Default.MinRamMb = (int)numMinRam.Value;
            Properties.Settings.Default.JavaPath = txtJavaPath.Text;
            Properties.Settings.Default.JvmArguments = txtJvmArgs.Text;
            Properties.Settings.Default.Save();
            this.Close();
        }
    }
}