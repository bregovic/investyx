
import {
    Dialog,
    DialogSurface,
    DialogTitle,
    DialogBody,
    DialogContent,
    DialogActions,
    Button,
    Dropdown,
    Option,
    Label,
    makeStyles
} from '@fluentui/react-components';
import { useTranslation } from '../context/TranslationContext';
import { useState } from 'react';

const useStyles = makeStyles({
    content: {
        display: 'flex',
        flexDirection: 'column',
        gap: '16px',
        paddingTop: '10px',
        minHeight: '150px'
    }
});

export const SettingsDialog = ({ open, onOpenChange }: { open: boolean, onOpenChange: (open: boolean) => void }) => {
    const styles = useStyles();
    const { language, setLanguage, t } = useTranslation();
    const [saving, setSaving] = useState(false);

    const handleSave = async () => {
        setSaving(true);
        // TranslationContext saves immediately on setLanguage, so we just simulate delay or close
        setTimeout(() => {
            setSaving(false);
            onOpenChange(false);
        }, 500);
    };

    return (
        <Dialog open={open} onOpenChange={(_, data) => onOpenChange(data.open)}>
            <DialogSurface>
                <DialogBody>
                    <DialogTitle>{t('settings.title')}</DialogTitle>
                    <DialogContent className={styles.content}>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '5px' }}>
                            <Label>{t('settings.language')}</Label>
                            <Dropdown
                                value={language === 'cs' ? 'Čeština' : 'English'}
                                onOptionSelect={(_, data) => setLanguage(data.optionValue as any)}
                            >
                                <Option value="cs" text="Čeština">Čeština</Option>
                                <Option value="en" text="English">English</Option>
                            </Dropdown>
                        </div>
                    </DialogContent>
                    <DialogActions>
                        <Button appearance="secondary" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button appearance="primary" onClick={handleSave} disabled={saving}>
                            {saving ? t('common.loading') : t('common.save')}
                        </Button>
                    </DialogActions>
                </DialogBody>
            </DialogSurface>
        </Dialog>
    );
};
